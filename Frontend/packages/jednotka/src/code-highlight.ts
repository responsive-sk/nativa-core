/**
 * code-highlight.ts — dependency-free syntax highlighter + copy buttons.
 *
 * Shared by every public theme entry (main.ts starter, erik.ts): tokenizes
 * fenced code blocks client-side and emits .tok-* spans; the theme CSS
 * scopes the token colors (.st-article .tok-*, .er-article .tok-*).
 *
 * ES2015-safe: no `?.`, `??` or other Chrome 70-unsupported syntax.
 */

export type Token = { type: string; value: string }

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
  [/^[|&;<>]+/, 'operator'], [/^[ \t]+/, ''], [/^[^\s#'";|&<>]+/, 'string'],
]

const SQL_RULES: Array<[RegExp, string]> = [
  [/^--[^\n]*/, 'comment'], [/^\/\*[\s\S]*?\*\//, 'comment'], [/^'(?:[^'\\]|\\.)*'/, 'string'],
  [/^\d+\.?\d*/, 'number'],
  [/^(?:SELECT|FROM|WHERE|INSERT|INTO|VALUES|UPDATE|SET|DELETE|CREATE|TABLE|DROP|ALTER|INDEX|JOIN|LEFT|RIGHT|INNER|OUTER|ON|AND|OR|NOT|IN|EXISTS|BETWEEN|LIKE|IS|NULL|AS|ORDER|BY|GROUP|HAVING|LIMIT|OFFSET|UNION|ALL|DISTINCT|COUNT|SUM|AVG|MIN|MAX|CASE|WHEN|THEN|ELSE|END|BEGIN|COMMIT|ROLLBACK|GRANT|REVOKE|PRIMARY|KEY|FOREIGN|REFERENCES|CONSTRAINT|DEFAULT|AUTO_INCREMENT|INTEGER|VARCHAR|TEXT|BOOLEAN|DATE|TIMESTAMP)\b/i, 'keyword'],
  [/^[a-zA-Z_]\w*/, 'property'],  [new RegExp('^[{}()\\[\\];,.:*/+=\x3c\x3e\x27-]+'), 'operator'], [/^[ \t]+/, ''],
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
  [/^->|=>|::|\.\.|\.\.=|&&=|\|\|=|\+=|-=|\*=|\/=|%=|&=|\|=|\^=|<<=|>>=|!\?\?\?|\.\.\.?|\bfn\b/, 'operator'],
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
  [new RegExp('^&[a-zA-Z]+;'), 'number'], [new RegExp('^[ \t]+'), ''],
  [new RegExp('^[^<]+'), ''],
]

const XML_RULES: Array<[RegExp, string]> = [
  [new RegExp('^<!--[\\s\\S]*?-->'), 'comment'],
  [new RegExp('^<\\?[\\s\\S]{0,200}'), 'keyword'],
  [new RegExp('^<\\/?[a-zA-Z][\\w:-]*'), 'tag'],
  [new RegExp('^[a-zA-Z][\\w:-]*(?=\\s*=)'), 'property'],
  [new RegExp('^='), 'punctuation'],
  [new RegExp('^"[^"]*"'), 'string'], [new RegExp("^'[^']*'"), 'string'],
  [new RegExp('^&[a-zA-Z]+;'), 'number'], [new RegExp('^[ \t]+'), ''],
  [new RegExp('^[^<]+'), ''],
]

const RULES_MAP: Record<string, Array<[RegExp, string]>> = {
  php: PHP_RULES, ts: TS_RULES, typescript: TS_RULES, js: TS_RULES, javascript: TS_RULES,
  yaml: YAML_RULES, yml: YAML_RULES, bash: BASH_RULES, sh: BASH_RULES, shell: BASH_RULES,
  sql: SQL_RULES, json: JSON_RULES, html: HTML_RULES, xml: XML_RULES, rust: RUST_RULES,
}

function tokenize(code: string, rules: Array<[RegExp, string]>): Token[] {
  const tokens: Token[] = []
  let remaining = code
  while (remaining.length > 0) {
    let matched = false
    for (const [pattern, type] of rules) {
      const m = remaining.match(pattern)
      if (null !== m && 0 === m.index) {
        tokens.push({ type, value: m[0] })
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

function tokensToHtml(tokens: Token[]): string {
  return tokens
    .map((t) => '' !== t.type ? '<span class="tok-' + t.type + '">' + esc(t.value) + '</span>' : esc(t.value))
    .join('')
}

function tokenizeHtmlWithScript(code: string): Token[] {
  const tokens: Token[] = []
  const parts = code.split(/(<\/?(?:script|style)[^>]*>)/gi)
  let inScript = false
  let inStyle = false
  for (const part of parts) {
    if (/^<script[\s>]/i.test(part)) {
      tokens.push({ type: 'tag', value: part })
      inScript = true
    } else if (/^<\/script[\s>]/i.test(part)) {
      tokens.push({ type: 'tag', value: part })
      inScript = false
    } else if (/^<style[\s>]/i.test(part)) {
      tokens.push({ type: 'tag', value: part })
      inStyle = true
    } else if (/^<\/style[\s>]/i.test(part)) {
      tokens.push({ type: 'tag', value: part })
      inStyle = false
    } else if (inScript) {
      tokens.push(...tokenize(part, TS_RULES))
    } else if (inStyle) {
      tokens.push(...tokenize(part, TS_RULES))
    } else if (part.length > 0) {
      tokens.push(...tokenize(part, HTML_RULES))
    }
  }
  return tokens
}

function highlightCode(code: string, lang: string): string {
  if ('html' === lang || 'xml' === lang) return tokensToHtml(tokenizeHtmlWithScript(code))
  const rules = RULES_MAP[lang]
  if (!rules) return esc(code)
  return tokensToHtml(tokenize(code, rules))
}

function copyButton(code: HTMLElement): HTMLButtonElement {
  const btn = document.createElement('button')
  btn.className = 'copy-btn'
  btn.type = 'button'
  btn.textContent = 'Copy'
  btn.addEventListener('click', () => {
    navigator.clipboard.writeText(code.textContent ?? '').then(() => {
      btn.textContent = 'Copied!'; btn.classList.add('copied')
      setTimeout(() => { btn.textContent = 'Copy'; btn.classList.remove('copied') }, 2000)
    }).catch(() => {
      const ta = document.createElement('textarea')
      ta.value = code.textContent ?? ''; ta.style.position = 'fixed'; ta.style.opacity = '0'
      document.body.appendChild(ta); ta.select(); document.execCommand('copy'); document.body.removeChild(ta)
      btn.textContent = 'Copied!'; btn.classList.add('copied')
      setTimeout(() => { btn.textContent = 'Copy'; btn.classList.remove('copied') }, 2000)
    })
  })
  return btn
}

export function initCodeHighlight(): void {
  // Syntax highlighting
  document.querySelectorAll<HTMLElement>('pre code[class^="language-"]').forEach((code) => {
    if (code.dataset.highlighted) return
    const lang = code.className.replace('language-', '')
    code.innerHTML = highlightCode(code.textContent ?? '', lang)
    code.dataset.highlighted = 'true'
  })
  // Copy buttons
  document.querySelectorAll<HTMLPreElement>('pre').forEach((pre) => {
    const code = pre.querySelector('code')
    if (!code) return
    pre.appendChild(copyButton(code))
  })
}
