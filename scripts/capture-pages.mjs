import { chromium } from 'playwright';
import fs from 'node:fs/promises';
import path from 'node:path';

const baseUrl = process.env.BASE_URL || 'http://localhost:8091';
const pages = (process.env.PAGES || '/,/login').split(',').map(p => p.trim()).filter(Boolean);
const stamp = new Date().toISOString().replace(/[:.]/g, '-');
const outDir = path.join('artifacts', 'screenshots', stamp);

await fs.mkdir(outDir, { recursive: true });

const browser = await chromium.launch({ headless: true });
const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });

for (const route of pages) {
  const url = new URL(route, baseUrl).toString();
  const fileSafe = route.replace(/^\//, '').replace(/[^a-zA-Z0-9-_]+/g, '_') || 'home';
  const file = path.join(outDir, `${fileSafe}.png`);

  await page.goto(url, { waitUntil: 'networkidle', timeout: 30000 });
  await page.screenshot({ path: file, fullPage: true });
  console.log(`${url} -> ${file}`);
}

await browser.close();
console.log(`Saved screenshots in ${outDir}`);
