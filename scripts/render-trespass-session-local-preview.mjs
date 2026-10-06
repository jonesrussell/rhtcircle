import fs from 'node:fs';
import path from 'node:path';

const projectRoot = path.resolve(import.meta.dirname, '..');
const sourcePath = path.resolve(
  projectRoot,
  '../Sagamok-Accountability/working/trespass-bylaw-session-explainer-2026-07-28.md',
);
const shellPath = path.join(projectRoot, 'public/local-preview/waasmoowin-deal-public-record.html');
const outputPath = path.join(projectRoot, 'public/local-preview/trespass-bylaw-session.html');

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

  while (index < lines.length) {
    const line = lines[index].trim();
    if (line === '') {
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

    if (/^>\s?/.test(line)) {
      const quote = [];
      while (index < lines.length && /^>\s?/.test(lines[index].trim())) {
        quote.push(lines[index].trim().replace(/^>\s?/, ''));
        index += 1;
      }
      html.push(`<blockquote class="news-article__quote">${inline(quote.join(' '))}</blockquote>`);
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
        || /^(#{2,4})\s+/.test(candidate)
        || /^>\s?/.test(candidate)
        || /^-\s+/.test(candidate)
        || /^\d+\.\s+/.test(candidate)
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
const titleEnd = markdown.indexOf('\n');
const sourceMarker = '\n## Source\n';
const sourceIndex = markdown.indexOf(sourceMarker);
if (sourceIndex === -1) {
  throw new Error('Expected source section was not found.');
}

const bodyMarkdown = markdown.slice(titleEnd + 1, sourceIndex).trim();
const sourcesMarkdown = markdown.slice(sourceIndex + sourceMarker.length).trim();
const renderedBody = renderMarkdown(bodyMarkdown).replace('<p>', '<p class="lead">');

const articleHtml = `
<article class="news-article">
  <header class="news-article__header">
    <p class="news-kicker">Analysis | Sagamok</p>
    <h1>The Trespass By-law session was backwards</h1>
    <p class="news-article__deck">The July 28 recording shows how the draft began, what powers it creates, what was left for later and which questions only leadership could answer.</p>
    <p class="news-article__byline">
      <strong>By Russell Jones</strong><br>
      <time datetime="2026-07-28">July 28, 2026</time> | RHT Circle analysis
    </p>
    <p class="news-preview-warning"><strong>Editorial preview.</strong> This article has not been published.</p>
  </header>

  <div class="news-article__plain">
    <strong>The problem with the session</strong>
    <p>The lawyers could explain the document. They could not answer what Council intends to do with it.</p>
  </div>

  <div class="news-article__grid">
    <div class="news-article__story">
      ${renderedBody}
    </div>
    <aside class="news-article__aside" aria-label="Key facts from the session">
      <div class="news-article__sticky">
        <div class="news-fact news-fact--dark">
          <div class="news-fact__label">Leadership answering for Council</div>
          <div class="news-fact__number">0</div>
          <div class="news-fact__text">The lawyers presented the draft. No Chief or Councillor answered members from the front.</div>
        </div>
        <div class="news-fact">
          <div class="news-fact__label">Existing banishment orders</div>
          <div class="news-fact__number">20+</div>
          <div class="news-fact__text">The presentation said they would remain in effect.</div>
        </div>
        <div class="news-fact">
          <div class="news-fact__label">Appeal window</div>
          <div class="news-fact__number">20 days</div>
          <div class="news-fact__text">The person must appeal. The Appeal Committee has not been designed.</div>
        </div>
        <div class="news-fact">
          <div class="news-fact__label">Implementation period</div>
          <div class="news-fact__number">60–90 days</div>
          <div class="news-fact__text">Officer, appeal, information-sharing and enforcement details would be completed later.</div>
        </div>
        <div class="news-timeline">
          <h2>What the recording says</h2>
          <div class="news-timeline__item"><time>00:10</time><p>Council and Community Justice asked for an enforceable law.</p></div>
          <div class="news-timeline__item"><time>00:19</time><p>The firm chose a new Trespass By-law instead of amending the Residency By-law.</p></div>
          <div class="news-timeline__item"><time>01:20</time><p>The lawyers said Council decides whether to approve it.</p></div>
          <div class="news-timeline__item"><time>01:41</time><p>Community Justice said it could not speak for Council.</p></div>
        </div>
      </div>
    </aside>
  </div>

  <section class="news-article__sources" aria-label="Sources and limits">
    <h2>Sources and limits of the record</h2>
    <details open>
      <summary>Session record</summary>
      <div>
        ${renderMarkdown(sourcesMarkdown)}
      </div>
    </details>
    <details>
      <summary>Editorial boundary</summary>
      <div>
        <p>The recording establishes what was said at the session. It does not establish what Council will decide, whether APS will agree to enforce the draft or what the final policies will contain.</p>
      </div>
    </details>
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
  .replace(/<title>.*?<\/title>/s, '<title>The Trespass By-law session was backwards | RHT Circle editorial preview</title>')
  .replace(/<meta name="description" content=".*?">/s, '<meta name="description" content="The July 28 recording shows how Sagamok&#39;s Trespass By-law draft began, what powers it creates and which questions only leadership could answer.">')
  .replace(/<meta property="og:url" content=".*?">/s, '<meta property="og:url" content="http://127.0.0.1:8102/local-preview/trespass-bylaw-session.html">')
  .replace(/<link rel="canonical" href=".*?">/s, '<link rel="canonical" href="http://127.0.0.1:8102/local-preview/trespass-bylaw-session.html">')
  .replace(/<meta property="og:title" content=".*?">/s, '<meta property="og:title" content="The Trespass By-law session was backwards">')
  .replace(/<meta property="og:description" content=".*?">/s, '<meta property="og:description" content="The lawyers could explain the document. They could not answer what Council intends to do with it.">')
  .replace(/<meta name="twitter:title" content=".*?">/s, '<meta name="twitter:title" content="The Trespass By-law session was backwards">')
  .replace(/<meta name="twitter:description" content=".*?">/s, '<meta name="twitter:description" content="The recording shows what the draft does, what was left for later and what Council did not answer.">')
  .replace(/\s*<meta property="og:image"[\s\S]*?<meta name="twitter:card" content="summary_large_image">/s, '\n  <meta name="robots" content="noindex, nofollow, noarchive, nosnippet">\n  <meta name="twitter:card" content="summary">')
  .replace(/\s*<meta name="twitter:image" content=".*?">/s, '')
  .replace(/\s*<script defer src="\/js\/rht-anokii-chat\.js[^"]*"><\/script>/s, '');

fs.writeFileSync(outputPath, shell);
console.log(outputPath);
