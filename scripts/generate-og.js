#!/usr/bin/env node
// Renders OG (social-card) PNGs for rhtcircle.ca.
// Requires: npm install playwright && npx playwright install chromium
//
// Two sources of cards:
//   1. Hand-crafted overrides - bespoke layouts, listed in `overrides` below by
//      template path. The site default (og-default.png) is rendered this way.
//   2. Auto-discovered pages - every templates/**/*.html.twig that extends
//      base.html.twig and isn't ignored gets a generic card rendered from
//      og-template-auto.html, using the page's own {% block title %} and
//      {% block description %} content.
//
// Add a hand-crafted card by creating its template file and registering it in
// `overrides`. Add a new page anywhere under templates/ that extends base and
// the next run renders a card for it automatically.
//
// The app serves public/images/og/<slug>.png if it exists (slug = template path
// with .html.twig stripped and '/' replaced by '-'; see src/Support/View.php),
// else falls back to og-default.png. Cards are committed to the repo and baked
// into the image at build time; Chromium never runs on the Pi.
//
// Run: node scripts/generate-og.js   (add --only-missing to fill gaps only)

const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const projectRoot = path.join(__dirname, '..');
const templatesDir = path.join(projectRoot, 'templates');
const scriptDir = __dirname;
const imagesDir = path.join(projectRoot, 'public', 'images');

// --only-missing (or OG_ONLY_MISSING=1): render only cards whose PNG does not
// already exist, leaving existing cards byte-for-byte untouched. This is the
// mode CI runs so a push never churns every card; it only fills gaps for new
// pages. Run without the flag locally to deliberately refresh existing cards.
const onlyMissing = process.argv.includes('--only-missing') || process.env.OG_ONLY_MISSING === '1';
const onlyTemplateArg = process.argv.find(arg => arg.startsWith('--only-template='));
const onlyTemplate = onlyTemplateArg ? onlyTemplateArg.slice('--only-template='.length) : null;

function skipBecauseExists(outputAbs, label) {
  if (onlyMissing && fs.existsSync(outputAbs)) {
    console.log('skip (exists): ', path.relative(projectRoot, outputAbs), label ? `(${label})` : '');
    return true;
  }
  return false;
}

// Hand-crafted overrides, keyed by the template path they belong to (relative
// to templates/). Auto-discovery skips these. Output paths are relative to
// public/images/.
const overrides = {
  // Site default (the failsafe card, not tied to a single template).
  '__default__': { template: 'og-template.html', output: 'og-default.png' },
  // Bespoke card for the youth baseball league: a baseball motif + the three
  // participating communities, on-brand (not the mockup teal).
  'pages/community-life/indigenous-baseball-league.html.twig':
    { template: 'og-template-baseball.html', output: 'og/pages-community-life-indigenous-baseball-league.png' },
};

// Routes that aren't directly derivable from a template path (for the URL
// watermark on the card). For most pages the route is the template path with
// the 'pages/' prefix and '.html.twig' stripped.
const routeOverrides = {
  'pages/home.html.twig': '/',
  'pages/communities/sagamok/account-or-resign.html.twig': '/communities/sagamok/member-accountability-resolution',
};

// Files / directories under templates/ to ignore when auto-discovering. These
// are partials, admin views, or per-entity shells rendered many times with
// dynamic data (their {% block title %} is a Twig variable, not a real page).
const ignoreTemplatePatterns = [
  /^_/,                                   // leading underscore = partial convention
  /^admin\//,                             // admin views - internal, not socially shared
  /^petition\//,                          // dynamic petition result shell
  /(^|\/)_/,                              // any partial dir
  /pages\/communities\/nation\.html\.twig$/, // per-nation shell (21 nations)
  /pages\/land\/project\.html\.twig$/,    // per-project shell
];

function listTwigTemplates(dir, base = dir) {
  const out = [];
  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) {
      out.push(...listTwigTemplates(full, base));
    } else if (entry.isFile() && entry.name.endsWith('.html.twig')) {
      // Normalise to forward slashes so the slug/url/ignore logic (which all
      // assume '/') works on Windows too, where path.relative yields '\'.
      out.push(path.relative(base, full).split(path.sep).join('/'));
    }
  }
  return out;
}

function shouldIgnore(relPath) {
  return ignoreTemplatePatterns.some(rx => rx.test(relPath));
}

function extendsBase(html) {
  return /\{%\s*extends\s+['"]base\.html\.twig['"]\s*%\}/.test(html);
}

function readBlock(html, blockName) {
  const rx = new RegExp(`\\{%\\s*block\\s+${blockName}\\s*%\\}([\\s\\S]*?)\\{%\\s*endblock(?:\\s+${blockName})?\\s*%\\}`);
  const m = html.match(rx);
  if (!m) return null;
  return m[1].trim();
}

// Strip the site title suffix so the card headline reads cleanly.
function cleanTitle(raw) {
  return raw
    .replace(/\s*·\s*Robinson Huron Treaty\s*$/i, '')
    .replace(/\s*·\s*RHT.*$/i, '')
    .replace(/&amp;/g, '&')
    .replace(/&nbsp;/g, ' ')
    .trim();
}

function cleanDescription(raw) {
  return raw
    .replace(/&amp;/g, '&')
    .replace(/&nbsp;/g, ' ')
    .replace(/\s+/g, ' ')
    .trim();
}

// Escape so page-provided text can't break the HTML we substitute it into.
function htmlEscape(s) {
  return s
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

// Template path -> card filename slug. Mirrors src/Support/View.php exactly:
// "pages/standard/records-request.html.twig" -> "pages-standard-records-request".
function slugForTemplate(relPath) {
  return relPath.replace(/\.html\.twig$/, '').replace(/\//g, '-');
}

// Template path -> public route for the URL watermark (no scheme). Strips the
// 'pages/' prefix (templates live under pages/, routes do not).
function urlForTemplate(relPath) {
  if (routeOverrides[relPath] !== undefined) {
    const r = routeOverrides[relPath];
    return r === '/' ? 'rhtcircle.ca' : 'rhtcircle.ca' + r.replace(/\/$/, '');
  }
  const route = relPath.replace(/\.html\.twig$/, '').replace(/^pages\//, '');
  return 'rhtcircle.ca/' + route;
}

function discoverAutoPages() {
  const all = listTwigTemplates(templatesDir);
  const overrideKeys = new Set(Object.keys(overrides));
  const pages = [];
  for (const rel of all) {
    if (shouldIgnore(rel)) continue;
    if (overrideKeys.has(rel)) continue;
    if (rel === 'base.html.twig') continue;
    const html = fs.readFileSync(path.join(templatesDir, rel), 'utf-8');
    if (!extendsBase(html)) continue;
    const titleRaw = readBlock(html, 'title');
    const descRaw = readBlock(html, 'description');
    if (!titleRaw || !descRaw) {
      console.warn('skip (missing title/description block):', rel);
      continue;
    }
    // A dynamic (variable) title/description belongs to a per-entity shell, not
    // a single page; skip rather than bake literal Twig into a card.
    if (/\{\{|\{%/.test(titleRaw) || /\{\{|\{%/.test(descRaw)) {
      console.warn('skip (dynamic title/description):', rel);
      continue;
    }
    pages.push({
      templatePath: rel,
      title: cleanTitle(titleRaw),
      description: cleanDescription(descRaw),
      url: urlForTemplate(rel),
      output: `og/${slugForTemplate(rel)}.png`,
    });
  }
  return pages;
}

async function renderTemplateString(page, html, outputAbs) {
  fs.mkdirSync(path.dirname(outputAbs), { recursive: true });
  await page.setContent(html, { waitUntil: 'load' });
  // Wait for the webfonts (Fraunces / Inter) so the card is brand-accurate;
  // never hang if they fail to load.
  try {
    await page.evaluate(() => document.fonts && document.fonts.ready);
    await page.waitForTimeout(250);
  } catch (e) { /* fonts API unavailable: render with the fallback stack */ }
  await page.screenshot({ path: outputAbs, type: 'png' });
}

async function main() {
  const browser = await chromium.launch();
  try {
    const page = await browser.newPage();
    await page.setViewportSize({ width: 1200, height: 630 });

    // 1. Hand-crafted overrides (use the dedicated template file as-is).
    for (const [key, { template, output }] of Object.entries(overrides)) {
      if (onlyTemplate !== null && key !== onlyTemplate) continue;
      const tplPath = path.join(scriptDir, template);
      const outPath = path.join(imagesDir, output);
      if (skipBecauseExists(outPath, key === '__default__' ? 'default' : key)) continue;
      const html = fs.readFileSync(tplPath, 'utf-8');
      await renderTemplateString(page, html, outPath);
      console.log('hand-crafted:', path.relative(projectRoot, outPath), key === '__default__' ? '(default)' : `(for ${key})`);
    }

    // 2. Auto-discovered pages (substitute into og-template-auto.html).
    const autoTemplate = fs.readFileSync(path.join(scriptDir, 'og-template-auto.html'), 'utf-8');
    const pages = discoverAutoPages().filter(p => onlyTemplate === null || p.templatePath === onlyTemplate);
    for (const p of pages) {
      const outPath = path.join(imagesDir, p.output);
      if (skipBecauseExists(outPath, p.title.slice(0, 40))) continue;
      const html = autoTemplate
        .replace('{{TITLE}}', htmlEscape(p.title))
        .replace('{{DESCRIPTION}}', htmlEscape(p.description))
        .replace('{{URL}}', htmlEscape(p.url));
      await renderTemplateString(page, html, outPath);
      console.log('auto:        ', path.relative(projectRoot, outPath), `(${p.title.slice(0, 50)}${p.title.length > 50 ? '…' : ''})`);
    }

    if (pages.length === 0 && Object.keys(overrides).filter(key => onlyTemplate === null || key === onlyTemplate).length === 0) {
      console.warn('No cards rendered. Check that templates/ has base-extending pages or that overrides is populated.');
    }
  } finally {
    await browser.close();
  }
}

main().catch(err => { console.error(err); process.exit(1); });
