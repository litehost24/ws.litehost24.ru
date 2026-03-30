import { isMode } from "./openai-mode-presets.mjs";

const MODE_RE = /^\s*mode\s*:\s*(low|medium|high|xhigh)\s*$/i;

export function parseModeAndStrip(text) {
  const lines = String(text ?? "").split(/\r?\n/);
  const first = lines[0] ?? "";
  const match = first.match(MODE_RE);

  if (!match) {
    return { mode: "medium", cleaned: String(text ?? ""), hasModeHeader: false };
  }

  const candidate = match[1].toLowerCase();
  const mode = isMode(candidate) ? candidate : "medium";
  const cleaned = lines.slice(1).join("\n").trimStart();
  return { mode, cleaned, hasModeHeader: true };
}
