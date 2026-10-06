import fs from 'node:fs';
import path from 'node:path';

const projectRoot = path.resolve(import.meta.dirname, '..');
const sourcePath = path.resolve(
  projectRoot,
  '../Sagamok-Accountability/working/drafts/rsg-guindon-sagamok-serpent-river-DRAFT-2026-07-27.md',
);
const shellPath = path.join(projectRoot, 'public/local-preview/waasmoowin-deal-public-record.html');
const outputPath = path.join(projectRoot, 'public/local-preview/one-contractor-two-first-nations.html');

const escapeHtml = (value) => value
  .replaceAll('&', '&amp;')
  .replaceAll('<', '&lt;')
  .replaceAll('>', '&gt;')
  .replaceAll('"', '&quot;');

const inline = (value) => {
  let result = escapeHtml(value);
  result = result.replace(/\[([^\]]+)\]\((https?:\/\/[^)]+)\)/g, '<a href="$2">$1</a>');
  result = result.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
  result = result.replace(/`([^`]+)`/g, '<code>$1</code>');
  return result;
};

function renderMarkdown(markdown) {
  const lines = markdown.replaceAll('\r\n', '\n').split('\n');
  const html = [];
  let index = 0;

  const isTableDivider = (line) => /^\s*\|?(?:\s*:?-+:?\s*\|)+\s*:?-+:?\s*\|?\s*$/.test(line);

  while (index < lines.length) {
    const line = lines[index].trim();
    if (line === '') {
      index += 1;
      continue;
    }

    if (line === '---') {
      html.push('<hr>');
      index += 1;
      continue;
    }

    const heading = /^(#{2,4})\s+(.+)$/.exec(line);
    if (heading) {
      const level = Math.min(4, heading[1].length);
      html.push(`<h${level}>${inline(heading[2])}</h${level}>`);
      index += 1;
      continue;
    }

    if (line.startsWith('|') && index + 1 < lines.length && isTableDivider(lines[index + 1])) {
      const rows = [];
      while (index < lines.length && lines[index].trim().startsWith('|')) {
        if (!isTableDivider(lines[index])) {
          rows.push(lines[index].trim().replace(/^\||\|$/g, '').split('|').map((cell) => cell.trim()));
        }
        index += 1;
      }
      const [headers, ...bodyRows] = rows;
      html.push('<div class="news-table-wrap"><table><thead><tr>');
      headers.forEach((cell) => html.push(`<th>${inline(cell)}</th>`));
      html.push('</tr></thead><tbody>');
      bodyRows.forEach((row) => {
        html.push('<tr>');
        row.forEach((cell) => html.push(`<td>${inline(cell)}</td>`));
        html.push('</tr>');
      });
      html.push('</tbody></table></div>');
      continue;
    }

    if (/^-\s+/.test(line)) {
      html.push('<ul>');
      while (index < lines.length && /^-\s+/.test(lines[index].trim())) {
        html.push(`<li>${inline(lines[index].trim().replace(/^-\s+/, ''))}</li>`);
        index += 1;
      }
      html.push('</ul>');
      continue;
    }

    if (/^\d+\.\s+/.test(line)) {
      html.push('<ol>');
      while (index < lines.length && /^\d+\.\s+/.test(lines[index].trim())) {
        html.push(`<li>${inline(lines[index].trim().replace(/^\d+\.\s+/, ''))}</li>`);
        index += 1;
      }
      html.push('</ol>');
      continue;
    }

    const paragraph = [];
    while (index < lines.length) {
      const candidate = lines[index].trim();
      if (
        candidate === ''
        || candidate === '---'
        || /^(#{2,4})\s+/.test(candidate)
        || /^-\s+/.test(candidate)
        || /^\d+\.\s+/.test(candidate)
        || (candidate.startsWith('|') && index + 1 < lines.length && isTableDivider(lines[index + 1]))
      ) {
        break;
      }
      paragraph.push(candidate);
      index += 1;
    }
    html.push(`<p>${inline(paragraph.join(' '))}</p>`);
  }

  return html.join('\n');
}

const markdown = fs.readFileSync(sourcePath, 'utf8');
const sourceMarker = '\n---\n\n## Source notes for editorial review';
const sourceIndex = markdown.indexOf(sourceMarker);
if (sourceIndex === -1) {
  throw new Error('Expected source-notes marker was not found.');
}

const headerEnd = markdown.indexOf('**DRAFT FOR EDITORIAL REVIEW. NOT PUBLISHED.**');
if (headerEnd === -1) {
  throw new Error('Expected draft marker was not found.');
}

const bodyMarkdown = markdown
  .slice(headerEnd + '**DRAFT FOR EDITORIAL REVIEW. NOT PUBLISHED.**'.length, sourceIndex)
  .trim();
const sourcesMarkdown = markdown
  .slice(sourceIndex + sourceMarker.length)
  .replace(/^### Primary records\s*/m, '')
  .replace(/^### Prepublication reporting still required/m, '### Reporting still to complete')
  .trim();
const renderedBody = renderMarkdown(bodyMarkdown).replace('<p>', '<p class="lead">');

const articleHtml = `
<article class="news-article">
  <header class="news-article__header">
    <p class="news-kicker">Investigation | Sagamok</p>
    <h1>Bought for $1.2 million. Sold to Sagamok for $7 million.</h1>
    <p class="news-article__deck">Public records connect seller Paul Guindon's companies to Serpent River housing projects and a GR Truss financing record involving Sagamok adviser Ryan McLeod.</p>
    <p class="news-article__byline">
      <strong>By Russell Jones</strong><br>
      <time datetime="2026-07-27">July 27, 2026</time> | RHT Circle investigation
    </p>
    <p class="news-preview-warning"><strong>Editorial preview.</strong> This article has not been published.</p>
  </header>

  <div class="news-article__grid">
    <div class="news-article__story">
      ${renderedBody}
    </div>
    <aside class="news-article__aside" aria-label="Key facts and timeline">
      <div class="news-article__sticky">
        <div class="news-fact news-fact--dark">
          <div class="news-fact__label">South Market land</div>
          <div class="news-fact__number">$1.2M to $7M</div>
          <div class="news-fact__text">The same parcel, sold fifteen months apart.</div>
        </div>
        <div class="news-fact">
          <div class="news-fact__label">Sagamok project plan</div>
          <div class="news-fact__number">About $45M</div>
          <div class="news-fact__text">$7 million in equity and $38 million described as financed.</div>
        </div>
        <div class="news-fact">
          <div class="news-fact__label">Elliot Lake costs reported</div>
          <div class="news-fact__number">$988,711.82</div>
          <div class="news-fact__text">No building construction had begun by January 22, 2026.</div>
        </div>
        <div class="news-fact">
          <div class="news-fact__label">Sagamok-owned GR Truss</div>
          <div class="news-fact__number">$900,000</div>
          <div class="news-fact__text">Four Sagamok cashflow and equity approvals after acquisition.</div>
        </div>
        <div class="news-timeline">
          <h2>Key dates</h2>
          <div class="news-timeline__item"><time datetime="2023-08-08">August 8, 2023</time><p>Guindon's company buys South Market for $1.2 million.</p></div>
          <div class="news-timeline__item"><time datetime="2024-05-28">May 28, 2024</time><p>McLeod presents Serpent River's Elliot Lake opportunity involving RSG.</p></div>
          <div class="news-timeline__item"><time datetime="2024-10-08">October 8, 2024</time><p>Sagamok Council approves $7 million from the Treaty settlement for the Sault project.</p></div>
          <div class="news-timeline__item"><time datetime="2024-11-13">November 13, 2024</time><p>The land is transferred into Sagamok's project for $7 million.</p></div>
          <div class="news-timeline__item"><time datetime="2025-01-14">January 14, 2025</time><p>McLeod Consulting and Guindon's company are registered as secured parties over a GR Truss payloader.</p></div>
          <div class="news-timeline__item"><time datetime="2026-01-22">January 22, 2026</time><p>Serpent River orders an independent review of its two urban housing projects.</p></div>
        </div>
      </div>
    </aside>
  </div>

  <section class="news-article__sources" aria-label="Sources and reporting notes">
    <h2>Sources and reporting notes</h2>
    ${renderMarkdown(sourcesMarkdown)}
  </section>
</article>`;

let shell = fs.readFileSync(shellPath, 'utf8');
const articleStart = shell.indexOf('<article class="news-article">');
const articleEnd = shell.indexOf('</article>', articleStart);
if (articleStart === -1 || articleEnd === -1) {
  throw new Error('Could not locate the article shell.');
}

shell = shell.slice(0, articleStart) + articleHtml + shell.slice(articleEnd + '</article>'.length);
shell = shell
  .replace(/<title>.*?<\/title>/s, '<title>Bought for $1.2 million. Sold to Sagamok for $7 million. | RHT Circle editorial preview</title>')
  .replace(/<meta name="description" content=".*?">/s, '<meta name="description" content="Paul Guindon&#39;s company bought South Market for $1.2 million and sold it into Sagamok&#39;s project fifteen months later for $7 million.">')
  .replace(/<meta property="og:url" content=".*?">/s, '<meta property="og:url" content="http://127.0.0.1:8102/local-preview/one-contractor-two-first-nations.html">')
  .replace(/<link rel="canonical" href=".*?">/s, '<link rel="canonical" href="http://127.0.0.1:8102/local-preview/one-contractor-two-first-nations.html">')
  .replace(/<meta property="og:title" content=".*?">/s, '<meta property="og:title" content="Bought for $1.2 million. Sold to Sagamok for $7 million.">')
  .replace(/<meta property="og:description" content=".*?">/s, '<meta property="og:description" content="The seller was Sault contractor Paul Guindon. His companies also appear in Serpent River housing projects and a financing record involving a manufacturer Sagamok acquired in 2025.">')
  .replace(/<meta name="twitter:title" content=".*?">/s, '<meta name="twitter:title" content="Bought for $1.2 million. Sold to Sagamok for $7 million.">')
  .replace(/<meta name="twitter:description" content=".*?">/s, '<meta name="twitter:description" content="A $1.2 million property became a $7 million Sagamok land deal fifteen months later.">')
  .replace(/\s*<meta property="og:image"[\s\S]*?<meta name="twitter:card" content="summary_large_image">/s, '\n  <meta name="robots" content="noindex, nofollow, noarchive, nosnippet">\n  <meta name="twitter:card" content="summary">')
  .replace(/\s*<meta name="twitter:image" content=".*?">/s, '')
  .replace(/\s*<script defer src="\/js\/rht-anokii-chat\.js[^"]*"><\/script>/s, '');

fs.writeFileSync(outputPath, shell);
console.log(outputPath);
