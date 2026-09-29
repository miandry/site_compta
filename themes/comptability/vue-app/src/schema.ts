/**
 * Modèle Drupal printblue (opérations du relevé + personnes) consommé via
 * api_solutions. Seul ce fichier connaît les machine names des champs.
 */

export type Vocabulary = 'operation_type' | 'category' | 'method_payment' | 'caisse'

export type BundleName = 'operation' | 'person'

export type FieldKind =
  | 'text'
  | 'textarea'
  | 'email'
  | 'tel'
  | 'number'
  | 'money'
  | 'date'
  | 'boolean'
  | 'options'
  | 'term'
  | 'node'
  | 'file'
  | 'image'

export type FormValue = string | number | boolean | string[]
export type FormValues = Record<string, FormValue>

export interface LookupItem {
  id: string
  label: string
  values: FormValues
}

export interface FieldDef {
  key: string
  label: string
  /** En-tête de colonne plus court que le libellé du formulaire. */
  columnLabel?: string
  kind: FieldKind
  required?: boolean
  vocabulary?: Vocabulary
  target?: BundleName
  targetFilter?: (item: LookupItem) => boolean
  options?: Record<string, string>
  placeholder?: string
  /** Image : une seule (cardinalité 1) par défaut à plusieurs. */
  multiple?: boolean
  /** Terme / nœud : bouton « + » pour créer l'élément depuis le formulaire. */
  creatable?: boolean
}

export interface DeriveContext {
  termLabel: (vocabulary: Vocabulary, id: string) => string
  lookup: (bundle: BundleName, id: string) => LookupItem | undefined
}

/** Liste mobile (façon Android) : clés de champs, la première valeur non vide l'emporte. */
export interface MobileListDef {
  titleKeys: string[]
  subtitleKeys: string[]
  amountKey?: string
  dateKey?: string
  avatarKey: string
}

export interface BundleDef {
  bundle: BundleName
  mobile?: MobileListDef
  /** Champ Entrée / Sortie : signe et couleur des montants. */
  directionKey?: string
  /** Page détail : records d'un autre bundle qui référencent celui-ci. */
  related?: { bundle: BundleName; key: string; label: string }
  label: string
  plural: string
  description: string
  createLabel: string
  fields: FieldDef[]
  steps: { label: string; fields: string[] }[]
  columns: string[]
  filters: string[]
  /** Champ date filtrable par période (du … au …). */
  dateFilterKey?: string
  sortField: string
  sortOrder: 'ASC' | 'DESC'
  titleFrom?: (values: FormValues, ctx: DeriveContext) => string
  derive?: (values: FormValues, changed: string, ctx: DeriveContext) => void
  validate?: (values: FormValues) => string
}

export const VOCABULARIES: Record<Vocabulary, { label: string; description: string }> = {
  operation_type: {
    label: "Types d'opération",
    description: "Nature de l'opération : Versement espèces, Virement reçu, Virement émis, Retrait, Chèque, Frais bancaires.",
  },
  category: {
    label: 'Catégories',
    description: "Catégorie de l'opération pour le classement et les rapports (ex. Écolage, Vente, Salaire, Fournisseur).",
  },
  method_payment: {
    label: 'Moyens de paiement',
    description: "Moyen utilisé pour l'opération : Espèces, Virement bancaire, Chèque, MVola, Orange Money, Airtel Money.",
  },
  caisse: {
    label: 'Caisses',
    description: 'Caisse ou compte de trésorerie concerné (ex. Caisse principale, Caisse secondaire, Banque BOA, Compte MVola).',
  },
}

/** Valeurs de la liste Drupal field_mouvement_argent. */
export const MOUVEMENTS = { Entree: 'Entrée', Sortie: 'Sortie' }

/** « Sortie » (ou « Débit ») -> sortie d'argent. */
export function isDebitLabel(label: string): boolean {
  return /sortie|d[ée]bit/i.test(label)
}

export const BUNDLES: Record<BundleName, BundleDef> = {
  operation: {
    bundle: 'operation',
    label: 'Opération',
    plural: 'Opérations',
    description: 'Lignes du relevé : date, mouvement (entrée/sortie), montant, libellé, personne et justificatif.',
    createLabel: '+ Nouvelle opération',
    directionKey: 'field_mouvement_argent',
    fields: [
      { key: 'field_mouvement_argent', label: "Mouvement d'argent", columnLabel: 'Mouvement', kind: 'options', options: MOUVEMENTS, required: true },
      { key: 'field_amount', label: 'Montant (Ar)', columnLabel: 'Montant', kind: 'money', required: true },
      { key: 'field_label', label: 'Libellé', kind: 'textarea', required: true, placeholder: 'Écolage septembre — Rakoto' },
      { key: 'field_operation_date', label: 'Date opération', columnLabel: 'Date', kind: 'date', required: true },
      { key: 'field_person', label: 'Personne', kind: 'node', target: 'person', required: true, creatable: true },
      { key: 'field_operation_type', label: "Type d'opération", columnLabel: 'Type', kind: 'term', vocabulary: 'operation_type', creatable: true },
      { key: 'field_category', label: 'Catégorie', kind: 'term', vocabulary: 'category', required: true },
      { key: 'field_method_payment', label: 'Moyen de paiement', columnLabel: 'Moyen', kind: 'term', vocabulary: 'method_payment' },
      { key: 'field_caisse', label: 'Caisse', kind: 'term', vocabulary: 'caisse' },
      { key: 'field_image_prof', label: 'Image justificatif', columnLabel: 'Justificatif', kind: 'image', multiple: false },
    ],
    steps: [
      { label: 'Opération', fields: ['field_category', 'field_mouvement_argent', 'field_amount', 'field_label'] },
      { label: 'Date & personne', fields: ['field_operation_date', 'field_person'] },
      { label: 'Classement', fields: ['field_operation_type', 'field_method_payment', 'field_caisse'] },
      { label: 'Justificatif', fields: ['field_image_prof'] },
    ],
    columns: [
      'field_operation_date',
      'field_label',
      'field_person',
      'field_operation_type',
      'field_caisse',
      'field_mouvement_argent',
      'field_amount',
    ],
    filters: ['field_mouvement_argent', 'field_caisse', 'field_category', 'field_operation_type'],
    mobile: {
      titleKeys: ['field_label', 'title'],
      subtitleKeys: ['field_person', 'field_caisse'],
      amountKey: 'field_amount',
      dateKey: 'field_operation_date',
      avatarKey: 'field_person',
    },
    dateFilterKey: 'field_operation_date',
    sortField: 'nid',
    sortOrder: 'DESC',
    titleFrom: (v) => {
      const label = String(v.field_label ?? '').replace(/\s+/g, ' ').trim()
      return label.length > 120 ? `${label.slice(0, 117)}…` : label || `Opération ${v.field_operation_date}`
    },
  },

  person: {
    bundle: 'person',
    label: 'Personne',
    plural: 'Personnes',
    description: 'Personnes liées aux opérations.',
    createLabel: '+ Nouvelle personne',
    related: { bundle: 'operation', key: 'field_person', label: 'Opérations' },
    fields: [
      { key: 'title', label: 'Nom complet', kind: 'text', required: true, placeholder: 'Rakoto Jean' },
      { key: 'field_phone_number', label: 'Numéro de téléphone', columnLabel: 'Téléphone', kind: 'tel', placeholder: '034 00 000 00' },
    ],
    steps: [{ label: 'Personne', fields: ['title', 'field_phone_number'] }],
    columns: ['title', 'field_phone_number'],
    mobile: {
      titleKeys: ['title'],
      subtitleKeys: ['field_phone_number'],
      avatarKey: 'title',
    },
    filters: [],
    sortField: 'title',
    sortOrder: 'ASC',
  },
}

export function fieldDef(def: BundleDef, key: string): FieldDef {
  const field = def.fields.find((f) => f.key === key)
  if (!field) throw new Error(`Champ inconnu ${def.bundle}.${key}`)
  return field
}
