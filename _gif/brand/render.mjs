import { chromium } from 'playwright';
import { fileURLToPath } from 'url';
import path from 'path';
import fs from 'fs';

// args: htmlFile width height frames loopMs outDir
const [html, W, H, FRAMES, LOOP, outDir] = process.argv.slice(2);
const width = +W, height = +H, frames = +FRAMES, loopMs = +LOOP;
const dir = path.dirname(fileURLToPath(import.meta.url));
const htmlPath = path.resolve(dir, html);
fs.mkdirSync(outDir, { recursive: true });

const browser = await chromium.launch();
const page = await browser.newPage({
  viewport: { width, height },
  deviceScaleFactor: 2,
});
await page.goto('file://' + htmlPath);
await page.evaluate(async () => { await document.fonts.ready; });
// let one loop warm up (lazy paints)
await page.waitForTimeout(200);

for (let i = 0; i < frames; i++) {
  const t = (i * loopMs) / frames; // exclusive of loopMs -> seamless
  await page.evaluate((tt) => {
    document.getAnimations().forEach((a) => { a.pause(); a.currentTime = tt; });
  }, t);
  const n = String(i).padStart(3, '0');
  await page.screenshot({ path: path.join(outDir, `f_${n}.png`), omitBackground: false });
}
await browser.close();
console.log(`rendered ${frames} frames -> ${outDir}`);
