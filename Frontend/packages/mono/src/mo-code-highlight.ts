/**
 * mo-code-highlight.ts — dependency-free syntax highlighter + copy buttons.
 *
 * Ported from Modules/Mono/assets/scripts/mo-code-highlight.js; behaviour
 * is identical. The rule tables are the shared highlighter's (same rules,
 * same order, same .tok-* spans): PHP, TS/JS, YAML, Bash, SQL, JSON, Rust,
 * HTML, XML — copied, not imported, so this bundle stays independent the
 * way griffe's duplicated gr-code-highlight.ts does. Mono's own half is the
 * `mo-code-badge` language label and the idempotent init (a second run
 * never adds a second copy button).
 *
 * Self-gating: no-ops when the page renders no fenced blocks, so the
 * single mono bundle entry can call it on every page.
 *
 * ES2015-safe: no `?.`, `??` or other Chrome 70-unsupported syntax.
 */

export type MoToken = { type: string; value: string }

const PHP_RULES: Array<[RegExp, string]> = [
  [/^\/\/[^\n]*/, 'comment'], [/^\/\*[\s\S]*?\*\//, 'comment'], [/^#[^\n]*/, 'comment'],
  [/^'(?:[^'\\]|\\.)*'/, 'string'], [/^"(?:[^"\\]|\\.)*"/, 'string'],
  [/^\$\w+/, 'variable'],
  [/^(?:abstract|and|array|as|break|callable|case|catch|class|clone|const|continue|declare|default|die|do|echo|else|elseif|empty|enddeclare|endfor|endforeach|endif|endswitch|endwhile|eval|exit|extends|final|finally|fn|for|foreach|function|global|goto|if|implements|include|include_once|instanceof|insteadof|interface|isset|list|match|namespace|new|or|print|private|protected|public|readonly|require|require_once|return|static|switch|throw|trait|try|unset|use|var|while|xor|yield)\b/, 'keyword'],
  [/^(?:int|float|string|bool|array|object|callable|iterable|null|void|never|self|parent|static)\b/, 'type'],
  [/^(?:true|false|null)\b/, 'keyword'], [/^\d+\.?\d*/, 'number'],
  [/^->|::|\?->|\?\?/, 'operator'], [/^[a-zA-Z_]\w*(?=\s*\()/, 'function'],
  [/^[A-Z]\w*/, 'type'], [/^[a-zA-Z_]\w*/, 'property'],
  [/^[{}()\[\];,.:]/, 'punctuation'], [/^[ \t]+/, ''],
]

const TS_RULES: Array<[RegExp, string]> = [
  [/^\/\/[^\n]*/, 'comment'], [/^\/\*[\s\S]*?\*\//, 'comment'],
  [/^'(?:[^'\\]|\\.)*'/, 'string'], [/^"(?:[^"\\]|\\.)*"/, 'string'], [/^`(?:[^`\\]|\\.)*`/, 'string'],
  [/^(?:import|export|from|default|const|let|var|function|class|extends|implements|interface|type|enum|namespace|module|declare|abstract|as|async|await|new|return|if|else|for|while|do|switch|case|break|continue|throw|try|catch|finally|typeof|instanceof|in|of|yield|this|super|static|public|private|protected|readonly|get|set|infer|keyof|never|unknown|any|void|null|undefined|true|false)\b/, 'keyword'],
  [/^\d+\.?\d*/, 'number'], [/^[a-zA-Z_]\w*(?=\s*[<(])/, 'function'],
  [/^[A-Z]\w*/, 'type'], [/^[a-zA-Z_]\w*/, 'property'],
  [/^[{}()\[\];,.:!?=<>+\-*/%&|^~]+/, 'operator'], [/^[ \t]+/, ''],
]

const YAML_RULES: Array<[RegExp, string]> = [
  [/^#[^\n]*/, 'comment'], [/^'(?:[^'\\]|\\.)*'/, 'string'], [/^"(?:[^"\\]|\\.)*"/, 'string'],
  [/^\d+\.?\d*/, 'number'], [/^(?:true|false|null|yes|no|on|off)\b/, 'keyword'],
  [/^[a-zA-Z_][\w.-]*(?=\s*:)/, 'property'], [/^-[ \t]+/, 'punctuation'],
  [/^[{}()\[\],&*?|>!]/, 'punctuation'], [/^:[ \t]+/, 'punctuation'],
  [/^[ \t]+/, ''], [/^[^\s#'"{}()\[\],&*?|>!:-]+/, 'string'],
]

const BASH_RULES: Array<[RegExp, string]> = [
  [/^#[^\n]*/, 'comment'], [/^"(?:[^"\\]|\\.)*"/, 'string'], [/^'(?:[^'\\]|\\.)*'/, 'string'],
  [/^\$\{[^}]+\}/, 'variable'], [/^\$\w+/, 'variable'],
  [/^(?:if|then|else|elif|fi|for|while|do|done|case|esac|function|return|exit|local|export|source|alias|unalias|set|unset|shift|declare|typeset|readonly|eval|exec|trap|wait|kill|bg|fg|jobs|cd|pwd|echo|printf|read|test|let|true|false|break|continue|in|select)\b/, 'keyword'],
  [/^\d+/, 'number'], [/^\$\(/, 'punctuation'], [/^[a-zA-Z_]\w*/, 'function'],
  [/^[|&;<>]+/, 'operator'], [/^[ \t]+/, ''], [/^[^\s#'"';|&<>]+/, 'string'],
]

const SQL_RULES: Array<[RegExp, string]> = [
  [/^--[^\n]*/, 'comment'], [/^\/\*[\s\S]*?\*\//, 'comment'], [/^'(?:[^'\\]|\\.)*'/, 'string'],
  [/^\d+\.?\d*/, 'number'],
  [/^(?:SELECT|FROM|WHERE|INSERT|INTO|VALUES|UPDATE|SET|DELETE|CREATE|TABLE|DROP|ALTER|INDEX|JOIN|LEFT|RIGHT|INNER|OUTER|ON|AND|OR|NOT|IN|EXISTS|BETWEEN|LIKE|IS|NULL|AS|ORDER|BY|GROUP|HAVING|LIMIT|OFFSET|UNION|ALL|DISTINCT|COUNT|SUM|AVG|MIN|MAX|CASE|WHEN|THEN|ELSE|END|BEGIN|COMMIT|ROLLBACK|GRANT|REVOKE|PRIMARY|KEY|FOREIGN|REFERENCES|CONSTRAINT|DEFAULT|AUTO_INCREMENT|INTEGER|VARCHAR|TEXT|BOOLEAN|DATE|TIMESTAMP)\b/i, 'keyword'],
  [/^[a-zA-Z_]\w*/, 'property'], [new RegExp('^[{}()\\[\\];,.:*/+=\\x3c\\x3e\\x27-]+'), 'operator'], [/^[ \t]+/, ''],
]

const JSON_RULES: Array<[RegExp, string]> = [
  [/^"(?:[^"\\]|\\.)*"(?=\s*:)/, 'property'], [/^"(?:[^"\\]|\\.)*"/, 'string'], [/^-?\d+\.?\d*(?:[eE][+-]?\d+)?/, 'number'], [/^(?:true|false|null)\b/, 'keyword'], [/^[{}()\[\]:,]/, 'punctuation'], [/^[ \t]+/, ''],
]

const RUST_RULES: Array<[RegExp, string]> = [
  [/^\/\/[^\n]*/, 'comment'], [/^\/\*[\s\S]*?\*\//, 'comment'],
  [/^'(?:[^'\\\n]|\\.)*'/, 'string'], [/^"(?:[^"\\]|\\.)*"/, 'string'], [/^b"(?:[^"\\]|\\.)*"/, 'string'], [/^r#*["'].*?["']#*/, 'string'],
  [/^''[\w]+/, 'keyword'],
  [/^\b(?:as|async|await|break|const|continue|crate|dyn|else|enum|extern|fn|for|if|impl|in|let|loop|match|mod|move|mut|pub|ref|return|self|Self|static|struct|super|trait|type|unsafe|use|where|while|yield)\b/, 'keyword'],
  [/^\b(?:bool|char|f32|f64|i8|i16|i32|i64|i128|isize|str|u8|u16|u32|u64|u128|usize|String|Vec|Option|Result|Box|Rc|Arc|HashMap|HashSet|BTreeMap)\b/, 'type'],
  [/^\b(?:true|false)\b/, 'keyword'],
  [/^\b(?:println!|eprintln!|format!|vec!|assert!|assert_eq!|assert_ne!|debug_assert!|debug_assert_eq!|todo!|unimplemented!|unreachable!|panic!|include_str!|include_bytes!|cfg!|env!|option_env!|concat!|stringify!|module_path!|file!|line!|column!|const_assert!)\b/, 'function'],
  [/^\d+\.?\d*(?:f32|f64|i8|i16|i32|i64|i128|u8|u16|u32|u64|u128|isize|usize)?\b/, 'number'],
  [/^\b0[xX][0-9a-fA-F_]+\b/, 'number'], [/^\b0[bB][01_]+\b/, 'number'], [/^\b0[oO][0-7_]+\b/, 'number'],
  [/^->|=>|::|\.\.|\.\.=|&&=|\|\||=|\+=|-=|\*=|\/=|%=|&=|\|=|\^=|<<=|>>=|!\?\?\?|\.\.\.?|\bfn\b/, 'operator'],
  [/^\bfn\s+\w+/, 'function'],
  [/^\b(?:pub\s+)?(?:fn|struct|enum|impl|trait|type|const|static)\s+\w+/, 'function'],
  [/^[A-Z]\w*/, 'type'], [/^[a-zA-Z_]\w*/, 'property'],
  [/^[{}()\[\];,.:]/, 'punctuation'], [/^[ \t]+/, ''],
]

const HTML_RULES: Array<[RegExp, string]> = [
  [new RegExp('^<!--[\\s\\S]*?-->'), 'comment'],
  [new RegExp('^<!DOCTYPE[\\s\\S]{0,60}', 'i'), 'keyword'],
  [new RegExp('^<\\/?[a-zA-Z][\\w-]*'), 'tag'],
  [new RegExp('^[a-zA-Z][\\w-]*(?=\\s*=)'), 'property'],
  [new RegExp('^='), 'punctuation'],
  [new RegExp('^"[^"]*"'), 'string'], [new RegExp("^'[^']*'"), 'string'],
  [new RegExp('^&[a-zA-Z]+;'), 'number'], [new RegExp('^[ \\t]+'), ''],
  [new RegExp('^[^<]+'), ''],
]

const XML_RULES: Array<[RegExp, string]> = [
  [new RegExp('^<!--[\\s\\S]*?-->'), 'comment'],
  [new RegExp('^<\\?[\\s\\S]{0,200}'), 'keyword'],
  [new RegExp('^<\\/?[a-zA-Z][\\w:-]*'), 'tag'],
  [new RegExp('^[a-zA-Z][\\w:-]*(?=\\s*=)'), 'property'],
  [new RegExp('^='), 'punctuation'],
  [new RegExp('^"[^"]*"'), 'string'], [new RegExp("^'[^']*'"), 'string'],
  [new RegExp('^&[a-zA-Z]+;'), 'number'], [new RegExp('^[ \\t]+'), ''],
  [new RegExp('^[^<]+'), ''],
]

const RULES_MAP: Record<string, Array<[RegExp, string]>> = {
  php: PHP_RULES, ts: TS_RULES, typescript: TS_RULES, js: TS_RULES, javascript: TS_RULES,
  yaml: YAML_RULES, yml: YAML_RULES, bash: BASH_RULES, sh: BASH_RULES, shell: BASH_RULES,
  sql: SQL_RULES, json: JSON_RULES, html: HTML_RULES, xml: XML_RULES, rust: RUST_RULES,
}

function tokenize(code: string, rules: Array<[RegExp, string]>): MoToken[] {
  const tokens: MoToken[] = []
  let remaining = code
  while (remaining.length > 0) {
    let matched = false
    for (let i = 0; i < rules.length; i++) {
      const m = remaining.match(rules[i][0])
      if (null !== m && 0 === m.index) {
        tokens.push({ type: rules[i][1], value: m[0] })
        remaining = remaining.slice(m[0].length)
        matched = true
        break
      }
    }
    if (!matched) { tokens.push({ type: '', value: remaining[0] }); remaining = remaining.slice(1) }
  }
  return tokens
}

function esc(text: string): string {
  return text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;')
}

function tokensToHtml(tokens: MoToken[]): string {
  let html = ''
  for (let i = 0; i < tokens.length; i++) {
    html += '' !== tokens[i].type
      ? '<span class="tok-' + tokens[i].type + '">' + esc(tokens[i].value) + '</span>'
      : esc(tokens[i].value)
  }
  return html
}

function tokenizeHtmlWithScript(code: string): MoToken[] {
  let tokens: MoToken[] = []
  const parts = code.split(/(<\/?(?:script|style)[^>]*>)/gi)
  let inScript = false
  let inStyle = false
  for (let i = 0; i < parts.length; i++) {
    const part = parts[i]
    if (/^<script[\s>]/.test(part)) { tokens.push({ type: 'tag', value: part }); inScript = true }
    else if (/^<\/script[\s>]/.test(part)) { tokens.push({ type: 'tag', value: part }); inScript = false }
    else if (/^<style[\s>]/.test(part)) { tokens.push({ type: 'tag', value: part }); inStyle = true }
    else if (/^<\/style[\s>]/.test(part)) { tokens.push({ type: 'tag', value: part }); inStyle = false }
    else if (inScript) { tokens = tokens.concat(tokenize(part, TS_RULES)) }
    else if (inStyle) { tokens = tokens.concat(tokenize(part, TS_RULES)) }
    else if (part.length > 0) { tokens = tokens.concat(tokenize(part, HTML_RULES)) }
  }
  return tokens
}

function highlightCode(code: string, lang: string): string {
  if ('html' === lang || 'xml' === lang) return tokensToHtml(tokenizeHtmlWithScript(code))
  const rules = RULES_MAP[lang]
  if (!rules) return esc(code)
  return tokensToHtml(tokenize(code, rules))
}

function showCopied(btn: HTMLButtonElement): void {
  btn.textContent = 'Copied!'
  btn.classList.add('copied')
  setTimeout(() => {
    btn.textContent = 'Copy'
    btn.classList.remove('copied')
  }, 2000)
}

function copyFallback(code: HTMLElement, btn: HTMLButtonElement): void {
  const ta = document.createElement('textarea')
  ta.value = code.textContent || ''
  ta.style.position = 'fixed'
  ta.style.opacity = '0'
  document.body.appendChild(ta)
  ta.select()
  try { document.execCommand('copy') } catch (e) { /* clipboard unavailable — the button still confirms */ }
  document.body.removeChild(ta)
  showCopied(btn)
}

function copyButton(code: HTMLElement): HTMLButtonElement {
  const btn = document.createElement('button')
  btn.className = 'copy-btn'
  btn.type = 'button'
  btn.textContent = 'Copy'
  btn.addEventListener('click', () => {
    const text = code.textContent || ''
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(() => { showCopied(btn) }, () => { copyFallback(code, btn) })
    } else {
      copyFallback(code, btn)
    }
  })
  return btn
}

function langOf(code: HTMLElement): string {
  const m = /(?:^|\s)language-([^\s]+)/.exec(code.className || '')
  return m ? m[1] : ''
}

const NO_BADGE = ' plain plaintext text console terminal output log '

function langBadge(lang: string): HTMLSpanElement {
  const badge = document.createElement('span')
  badge.className = 'mo-code-badge'
  badge.textContent = lang.toUpperCase()
  return badge
}

function showBadge(lang: string): boolean {
  return '' !== lang && NO_BADGE.indexOf(' ' + lang.toLowerCase() + ' ') < 0
}

export function initMoCodeHighlight(): void {
  const blocks = document.querySelectorAll<HTMLElement>('pre code[class^="language-"]')
  for (let i = 0; i < blocks.length; i++) {
    const code = blocks[i]
    if (code.getAttribute('data-highlighted')) continue
    const lang = code.className.replace('language-', '')
    code.innerHTML = highlightCode(code.textContent || '', lang)
    code.setAttribute('data-highlighted', 'true')
  }
  const pres = document.querySelectorAll('pre')
  for (let j = 0; j < pres.length; j++) {
    const pre = pres[j]
    const code = pre.querySelector('code')
    if (!code) continue
    const badgeLang = langOf(code)
    if (showBadge(badgeLang) && !pre.querySelector('.mo-code-badge')) {
      pre.insertBefore(langBadge(badgeLang), pre.firstChild)
      pre.classList.add('has-badge')
    }
    if (pre.querySelector('.copy-btn')) continue
    pre.appendChild(copyButton(code))
  }
}
