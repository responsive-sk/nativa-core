/**
 * inline-editor-preview — the Markdown subset the live preview renders.
 *
 * Split out of `inline-editor.ts` to stay under the 600-line theme file cap;
 * the rendering itself is unchanged.
 *
 * Every top-level block is stamped with the source line it came from
 * (`data-editor-line`), which is what lets the preview be scrolled to the
 * paragraph the author is actually typing — see `inline-editor-scroll.ts` for
 * the reader.
 */

import {
  codeBlock,
  lineTag,
  renderInline,
  withLine,
} from './inline-editor';

function renderMarkdown(source: string): string {
  const lines = source.replace(/\r\n/g, '\n').split('\n');
  const out: string[] = [];
  let paragraph: string[] = [];
  let paragraphLine = 0;
  let listType: '' | 'ul' | 'ol' = '';
  let fence: string[] = [];
  let fenceLang = '';
  let fenceLine = 0;
  let inFence = false;

  const flushParagraph = (): void => {
    if (paragraph.length === 0) return;
    out.push('<p' + lineTag(paragraphLine) + '>' + renderInline(paragraph.join(' ')) + '</p>');
    paragraph = [];
  };
  const closeList = (): void => {
    if (listType === '') return;
    out.push('</' + listType + '>');
    listType = '';
  };

  // `i` is the 0-based index of the line being read, and it is the point of
  // this loop: every block records the source line it came from.
  for (let i = 0; i < lines.length; i++) {
    const line = lines[i];
    const fenceMatch = /^```(.*)$/.exec(line);

    if (fenceMatch !== null) {
      if (inFence) {
        // Tagged at the OPENING fence, not the closing ``` 40 lines below it.
        out.push(withLine(codeBlock(fence.join('\n'), fenceLang), fenceLine));
        fence = [];
        inFence = false;
        fenceLang = '';
      } else {
        flushParagraph();
        closeList();
        inFence = true;
        fenceLine = i;
        fenceLang = fenceMatch[1].trim();
      }
      continue;
    }

    if (inFence) {
      fence.push(line);
      continue;
    }

    if (line.trim() === '') {
      flushParagraph();
      closeList();
      continue;
    }

    const heading = /^(#{1,6})\s+(.*)$/.exec(line);
    if (heading !== null) {
      flushParagraph();
      closeList();
      const level = heading[1].length;
      out.push('<h' + level + lineTag(i) + '>' + renderInline(heading[2].trim()) + '</h' + level + '>');
      continue;
    }

    if (/^(---|\*\*\*|___)\s*$/.test(line)) {
      flushParagraph();
      closeList();
      out.push('<hr' + lineTag(i) + '>');
      continue;
    }

    if (line.indexOf('> ') === 0) {
      flushParagraph();
      closeList();
      out.push('<blockquote' + lineTag(i) + '>' + renderInline(line.slice(2)) + '</blockquote>');
      continue;
    }

    const task = /^\s*[-*]\s+\[([ xX])\]\s+(.*)$/.exec(line);
    const bullet = /^\s*[-*]\s+(.*)$/.exec(line);
    const ordered = /^\s*\d+\.\s+(.*)$/.exec(line);

    if (task !== null) {
      flushParagraph();
      if (listType !== 'ul') {
        closeList();
        out.push('<ul' + lineTag(i) + '>');
        listType = 'ul';
      }
      const checked = task[1].toLowerCase() === 'x' ? ' checked' : '';
      out.push('<li><input type="checkbox" disabled' + checked + '> ' + renderInline(task[2]) + '</li>');
      continue;
    }

    if (bullet !== null) {
      flushParagraph();
      if (listType !== 'ul') {
        closeList();
        out.push('<ul>');
        listType = 'ul';
      }
      out.push('<li>' + renderInline(bullet[1]) + '</li>');
      continue;
    }

    if (ordered !== null) {
      flushParagraph();
      if (listType !== 'ol') {
        closeList();
        out.push('<ol' + lineTag(i) + '>');
        listType = 'ol';
      }
      out.push('<li>' + renderInline(ordered[1]) + '</li>');
      continue;
    }

    closeList();
    if (paragraph.length === 0) paragraphLine = i;
    paragraph.push(line.trim());
  }

  if (inFence) {
    // An unclosed fence is a draft mid-typing, not an error: show the code.
    out.push(withLine(codeBlock(fence.join('\n'), fenceLang), fenceLine));
  }
  flushParagraph();
  closeList();

  return out.join('\n');
}

export { renderMarkdown };
