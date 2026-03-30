import process from "node:process";
import fs from "node:fs/promises";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { parseModeAndStrip } from "./openai-mode-parser.mjs";
import { MODE_PRESETS, isMode } from "./openai-mode-presets.mjs";

const DEFAULT_RESPONSES_MODEL = process.env.OPENAI_MODEL_RESPONSES || "gpt-5";
const DEFAULT_CHAT_MODEL = process.env.OPENAI_MODEL_CHAT || "gpt-4.1";
const API_BASE = process.env.OPENAI_BASE_URL || "https://api.openai.com/v1";
const SCRIPT_DIR = path.dirname(fileURLToPath(import.meta.url));
const STATE_FILE = process.env.OPENAI_MODE_FILE || path.resolve(SCRIPT_DIR, "../.llm_mode");

function usage() {
  console.error(
    [
      "Usage:",
      "  node scripts/openai-mode-router.mjs \"mode: high\\nYour prompt\"",
      "  echo -e \"mode: xhigh\\nYour prompt\" | node scripts/openai-mode-router.mjs",
      "Optional:",
      "  --mode <low|medium|high|xhigh>       # override mode",
      "  --mode-set <low|medium|high|xhigh>   # set persistent mode and exit",
      "  --mode-get                            # print persistent mode",
      "  --mode-clear                          # clear persistent mode",
    ].join("\n"),
  );
}

async function readStdinIfAny() {
  if (process.stdin.isTTY) return "";
  let data = "";
  for await (const chunk of process.stdin) {
    data += chunk;
  }
  return data;
}

function parseArgs(argv) {
  let explicitMode = null;
  let modeSet = null;
  let modeGet = false;
  let modeClear = false;
  const payloadParts = [];

  for (let i = 0; i < argv.length; i += 1) {
    const token = argv[i];
    if (token === "--mode") {
      explicitMode = (argv[i + 1] || "").toLowerCase();
      i += 1;
      continue;
    }
    if (token === "--mode-set") {
      modeSet = (argv[i + 1] || "").toLowerCase();
      i += 1;
      continue;
    }
    if (token === "--mode-get") {
      modeGet = true;
      continue;
    }
    if (token === "--mode-clear") {
      modeClear = true;
      continue;
    }
    payloadParts.push(token);
  }

  return {
    explicitMode,
    modeSet,
    modeGet,
    modeClear,
    payload: payloadParts.join(" ").trim(),
  };
}

async function readStoredMode() {
  try {
    const mode = (await fs.readFile(STATE_FILE, "utf8")).trim().toLowerCase();
    return isMode(mode) ? mode : null;
  } catch {
    return null;
  }
}

async function writeStoredMode(mode) {
  await fs.writeFile(STATE_FILE, `${mode}\n`, "utf8");
}

async function clearStoredMode() {
  try {
    await fs.unlink(STATE_FILE);
  } catch {
    // noop
  }
}

function getSystemPrompt(mode) {
  const base = "Ты прагматичный инженер. Отвечай по делу и без воды.";
  if (mode === "low") {
    return `${base} Кратко: только ключевые шаги и результат.`;
  }
  if (mode === "medium") {
    return `${base} Дай короткое решение и важные причины выбора.`;
  }
  return `${base} Дай детальное решение: риски, проверки и допущения.`;
}

async function postJson(path, apiKey, payload) {
  const response = await fetch(`${API_BASE}${path}`, {
    method: "POST",
    headers: {
      Authorization: `Bearer ${apiKey}`,
      "Content-Type": "application/json",
    },
    body: JSON.stringify(payload),
  });

  const text = await response.text();
  let body = null;
  try {
    body = text ? JSON.parse(text) : null;
  } catch {
    body = { raw: text };
  }

  return { ok: response.ok, status: response.status, body };
}

function extractResponsesText(body) {
  if (!body || typeof body !== "object") return "";
  if (typeof body.output_text === "string" && body.output_text.length > 0) {
    return body.output_text;
  }

  const chunks = [];
  const output = Array.isArray(body.output) ? body.output : [];
  for (const item of output) {
    const content = Array.isArray(item?.content) ? item.content : [];
    for (const c of content) {
      if (typeof c?.text === "string") chunks.push(c.text);
    }
  }
  return chunks.join("\n").trim();
}

function extractChatText(body) {
  return body?.choices?.[0]?.message?.content ?? "";
}

async function main() {
  const { explicitMode, modeSet, modeGet, modeClear, payload } = parseArgs(process.argv.slice(2));

  if (modeSet) {
    if (!isMode(modeSet)) {
      console.error("ERROR: invalid mode for --mode-set.");
      process.exit(1);
    }
    await writeStoredMode(modeSet);
    console.log(modeSet);
    return;
  }

  if (modeClear) {
    await clearStoredMode();
    console.log("cleared");
    return;
  }

  if (modeGet) {
    const stored = await readStoredMode();
    console.log(stored || "medium");
    return;
  }

  const apiKey = process.env.OPENAI_API_KEY;
  if (!apiKey) {
    console.error("ERROR: OPENAI_API_KEY is required.");
    process.exit(1);
  }

  const stdinText = await readStdinIfAny();
  const rawInput = payload || stdinText;
  if (!rawInput) {
    usage();
    process.exit(1);
  }

  const parsed = parseModeAndStrip(rawInput);
  const storedMode = await readStoredMode();
  const mode = isMode(explicitMode)
    ? explicitMode
    : (parsed.hasModeHeader ? parsed.mode : (storedMode || "medium"));

  // Any explicit mode in the prompt or flag becomes the new persistent default.
  if (isMode(explicitMode)) {
    await writeStoredMode(explicitMode);
  } else if (parsed.hasModeHeader) {
    await writeStoredMode(parsed.mode);
  }

  const cleaned = parsed.cleaned.trim();
  if (!cleaned) {
    console.error("ERROR: prompt is empty after mode parsing.");
    process.exit(1);
  }

  const preset = MODE_PRESETS[mode];
  const systemPrompt = getSystemPrompt(mode);

  const responsesPayload = {
    model: DEFAULT_RESPONSES_MODEL,
    input: [
      { role: "system", content: systemPrompt },
      { role: "user", content: cleaned },
    ],
    reasoning: { effort: preset.reasoning_effort },
    temperature: preset.temperature,
    max_output_tokens: preset.max_output_tokens,
  };

  const responsesResult = await postJson("/responses", apiKey, responsesPayload);
  if (responsesResult.ok) {
    const text = extractResponsesText(responsesResult.body);
    console.error(`[mode=${mode}] endpoint=responses model=${DEFAULT_RESPONSES_MODEL}`);
    process.stdout.write(text + "\n");
    return;
  }

  const chatPayload = {
    model: DEFAULT_CHAT_MODEL,
    messages: [
      { role: "system", content: systemPrompt },
      { role: "user", content: cleaned },
    ],
    temperature: preset.temperature,
    max_tokens: Math.min(preset.max_output_tokens, 2000),
  };

  const chatResult = await postJson("/chat/completions", apiKey, chatPayload);
  if (chatResult.ok) {
    const text = extractChatText(chatResult.body);
    console.error(`[mode=${mode}] endpoint=chat.completions model=${DEFAULT_CHAT_MODEL}`);
    process.stdout.write(text + "\n");
    return;
  }

  console.error(
    JSON.stringify(
      {
        error: "Both endpoints failed",
        responses: {
          status: responsesResult.status,
          body: responsesResult.body,
        },
        chat: {
          status: chatResult.status,
          body: chatResult.body,
        },
      },
      null,
      2,
    ),
  );
  process.exit(1);
}

await main();
