/**
 * Calculatrice des montants saisis : + − × ÷ et parenthèses, sans eval().
 *
 *   « -4545+2343 »        => -2202
 *   « (1231 + 3344)/345 » => 13.26…
 *   « 9 600 000 »         => 9600000   (espaces de milliers)
 *   « 1.500.000 »         => 1500000   (points / virgules de milliers : groupes de 3 chiffres)
 *   « 12,5 * 2 »          => 25        (virgule ou point décimal sinon)
 */

type Token = { type: 'num'; value: number } | { type: 'op'; value: string }

function normalize(input: string): string {
  return input
    .replace(/ar\s*$/i, '')
    .replace(/[−–—]/g, '-')
    .replace(/[×xX]/g, '*')
    .replace(/[÷:]/g, '/')
    // « 9 600 000 » : un espace entre deux chiffres est un séparateur de milliers.
    .replace(/(\d)[\s\u00a0\u202f]+(?=\d)/g, '$1')
}

function readNumber(src: string, start: number): [number, number] {
  const m = /^\d+(?:[.,]\d+)*/.exec(src.slice(start))
  if (!m) throw new Error(`caractère inattendu « ${src[start]} »`)
  const text = m[0]
  const parts = text.split(/[.,]/)
  let value: number
  if (parts.length === 1) value = Number(text)
  // Tous les groupes après le premier font 3 chiffres => séparateurs de milliers.
  else if (parts[0].length <= 3 && parts.slice(1).every((p) => p.length === 3)) value = Number(parts.join(''))
  else if (parts.length === 2) value = Number(`${parts[0]}.${parts[1]}`)
  else throw new Error(`nombre illisible « ${text} »`)
  return [value, start + text.length]
}

function tokenize(input: string): Token[] {
  const src = normalize(input)
  const tokens: Token[] = []
  let i = 0
  while (i < src.length) {
    const c = src[i]
    if (/\s/.test(c)) {
      i++
    } else if ('+-*/()'.includes(c)) {
      tokens.push({ type: 'op', value: c })
      i++
    } else if (/\d/.test(c)) {
      const [value, next] = readNumber(src, i)
      tokens.push({ type: 'num', value })
      i = next
    } else {
      throw new Error(`caractère inattendu « ${c} »`)
    }
  }
  return tokens
}

/** expr = term (± term)* ; term = factor (×÷ factor)* ; factor = ±factor | nombre | (expr) */
export function evaluate(input: string): number {
  const tokens = tokenize(input)
  if (!tokens.length) throw new Error('montant manquant')
  let pos = 0
  const peek = () => tokens[pos]
  const isOp = (v: string) => peek()?.type === 'op' && peek()!.value === v

  function factor(): number {
    const t = peek()
    if (!t) throw new Error('expression incomplète')
    if (isOp('-')) {
      pos++
      return -factor()
    }
    if (isOp('+')) {
      pos++
      return factor()
    }
    if (isOp('(')) {
      pos++
      const v = expr()
      if (!isOp(')')) throw new Error('parenthèse « ) » manquante')
      pos++
      return v
    }
    if (t.type === 'num') {
      pos++
      return t.value
    }
    throw new Error(`« ${t.value} » mal placé`)
  }

  function term(): number {
    let v = factor()
    while (isOp('*') || isOp('/')) {
      const op = (tokens[pos++] as { value: string }).value
      const rhs = factor()
      if (op === '/' && rhs === 0) throw new Error('division par zéro')
      v = op === '*' ? v * rhs : v / rhs
    }
    return v
  }

  function expr(): number {
    let v = term()
    while (isOp('+') || isOp('-')) {
      const op = (tokens[pos++] as { value: string }).value
      const rhs = term()
      v = op === '+' ? v + rhs : v - rhs
    }
    return v
  }

  const result = expr()
  if (pos < tokens.length) throw new Error(`« ${(peek() as { value: unknown }).value} » en trop`)
  if (!Number.isFinite(result)) throw new Error('résultat invalide')
  return result
}

/** Vrai si la saisie contient un vrai calcul (pas seulement un signe devant un nombre). */
export function isExpression(input: string): boolean {
  return /\d\s*[-+*/×xX÷:−–]|[()]/.test(normalize(input).trim())
}
