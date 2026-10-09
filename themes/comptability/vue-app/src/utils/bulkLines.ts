import { evaluate, isExpression } from './calc'

/** Une ligne de saisie multiple : « montant ou calcul #libellé ». */
export interface AmountLine {
  raw: string
  /** Partie avant « # » (nombre ou calcul). */
  amountPart: string
  label: string
  /** Résultat signé arrondi à l'ariary (négatif = sortie). */
  value: number
  /** Résultat exact du calcul. */
  exact: number
  expression: boolean
  rounded: boolean
  error: string
}

export function parseAmountLine(raw: string): AmountLine {
  const hash = raw.indexOf('#')
  const amountPart = (hash >= 0 ? raw.slice(0, hash) : raw).trim()
  const line: AmountLine = {
    raw,
    amountPart,
    label: hash >= 0 ? raw.slice(hash + 1).replace(/\s+/g, ' ').trim() : '',
    value: 0,
    exact: 0,
    expression: false,
    rounded: false,
    error: '',
  }
  if (!amountPart) {
    line.error = 'Montant manquant'
    return line
  }
  try {
    line.exact = evaluate(amountPart)
  } catch (err) {
    line.error = `Montant illisible « ${amountPart} » : ${(err as Error).message}`
    return line
  }
  line.value = Math.round(line.exact)
  if (!line.value) {
    line.error = `Montant nul (${amountPart} = ${line.exact})`
    return line
  }
  line.expression = isExpression(amountPart)
  line.rounded = line.value !== line.exact
  return line
}

/** Lignes non vides du texte. */
export function splitLines(text: string): string[] {
  return text
    .split(/\r?\n/)
    .map((l) => l.trim())
    .filter(Boolean)
}

/** Totaux par libellé (« # » = sans libellé), positif, négatif et net. */
export function lotBreakdown(text: string) {
  const byLabel = new Map<string, number>()
  let positive = 0
  let negative = 0
  for (const raw of splitLines(text)) {
    const line = parseAmountLine(raw)
    if (line.error) continue
    const key = line.label ? `#${line.label}` : '#'
    byLabel.set(key, (byLabel.get(key) ?? 0) + line.value)
    if (line.value > 0) positive += line.value
    else negative += line.value
  }
  return { byLabel: [...byLabel.entries()], positive, negative, net: positive + negative }
}
