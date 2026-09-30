<?php

namespace Drupal\comptabilty;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\node\NodeInterface;

/**
 * Entry point for the module's business logic.
 */
class ComptabiltyManager {

  /**
   * Operation fields tracked in revision logs, with their French label.
   */
  const OPERATION_FIELDS = [
    'field_amount' => 'Montant',
    'field_mouvement_argent' => 'Mouvement',
    'title' => 'Libellé',
    'field_label' => 'Libellé',
    'field_operation_date' => 'Date',
    'field_person' => 'Personne',
    'field_category' => 'Catégorie',
    'field_operation_type' => "Type d'opération",
    'field_method_payment' => 'Moyen de paiement',
    'field_caisse' => 'Caisse',
    'field_image_prof' => 'Justificatif',
    'status' => 'Statut',
  ];

  protected EntityTypeManagerInterface $entityTypeManager;

  protected AccountInterface $currentUser;

  public function __construct(EntityTypeManagerInterface $entity_type_manager, AccountInterface $current_user) {
    $this->entityTypeManager = $entity_type_manager;
    $this->currentUser = $current_user;
  }

  /**
   * Forces a new revision on an operation and describes what changed.
   */
  public function prepareOperationRevision(NodeInterface $node): void {
    if (!$node->isNew()) {
      $node->setNewRevision(TRUE);
    }
    // api_solutions authenticates by token, so the Drupal current user is anonymous there.
    $author = \Drupal::service('comptability_access.api_user')->get();
    $node->setRevisionUserId($author ? $author->id() : $this->currentUser->id());
    $node->setRevisionCreationTime(\Drupal::time()->getRequestTime());
    $node->setRevisionLogMessage($node->isNew() ? $this->describeInsert($node) : $this->describeChanges($node, $node->original));
  }

  protected function describeInsert(NodeInterface $node): string {
    $parts = [];
    foreach (['field_mouvement_argent', 'field_amount', 'field_category', 'field_person'] as $name) {
      if ($node->hasField($name) && !$node->get($name)->isEmpty()) {
        $parts[] = $this->format($node->get($name));
      }
    }
    return 'Nouvelle opération' . ($parts ? ' : ' . implode(' · ', $parts) : '');
  }

  protected function describeChanges(NodeInterface $node, ?NodeInterface $original): string {
    if (!$original) {
      return 'Modification';
    }
    if ($original->isPublished() && !$node->isPublished()) {
      return 'Suppression de l\'opération (' . $this->format($original->get('field_amount')) . ')';
    }
    $changes = [];
    foreach (self::OPERATION_FIELDS as $name => $label) {
      if ($name === 'status' || !$node->hasField($name)) {
        continue;
      }
      $new = $node->get($name);
      $old = $original->get($name);
      if ($this->sameValue($old, $new)) {
        continue;
      }
      $changes[] = $name === 'field_image_prof'
        ? "{$label} modifié"
        : "{$label} : {$this->format($old)} → {$this->format($new)}";
    }
    if (!$original->isPublished() && $node->isPublished()) {
      $changes[] = 'Opération restaurée';
    }
    return $changes ? implode(' ; ', $changes) : 'Enregistrement sans modification';
  }

  protected function sameValue(FieldItemListInterface $a, FieldItemListInterface $b): bool {
    $main = $a->getFieldDefinition()->getFieldStorageDefinition()->getMainPropertyName() ?: 'value';
    $values = fn(FieldItemListInterface $items) => array_map(fn($item) => (string) ($item[$main] ?? ''), $items->getValue());
    return $values($a) === $values($b);
  }

  /**
   * Human readable value of a field, as shown in revision logs.
   */
  public function format(FieldItemListInterface $items): string {
    if ($items->isEmpty()) {
      return '—';
    }
    $definition = $items->getFieldDefinition();
    switch ($definition->getType()) {
      case 'entity_reference':
        $labels = array_map(fn($entity) => $entity->label(), $items->referencedEntities());
        return $labels ? implode(', ', $labels) : '—';

      case 'image':
      case 'file':
        return (string) count($items) . ' fichier(s)';

      case 'list_string':
      case 'list_integer':
        $allowed = ['Entree' => 'Entrée'] + ($definition->getSetting('allowed_values') ?: []);
        return implode(', ', array_map(fn($item) => (string) ($allowed[$item['value']] ?? $item['value']), $items->getValue()));

      case 'integer':
      case 'decimal':
      case 'float':
        $value = number_format((float) $items->value, 0, ',', ' ');
        return $definition->getName() === 'field_amount' ? "{$value} Ar" : $value;

      case 'datetime':
        $time = strtotime((string) $items->value);
        return $time ? date('d/m/Y', $time) : (string) $items->value;

      case 'boolean':
        return $items->value ? 'oui' : 'non';

      default:
        $text = trim(strip_tags((string) $items->value));
        return mb_strlen($text) > 60 ? mb_substr($text, 0, 57) . '…' : $text;
    }
  }

  /**
   * Revision history of an operation, newest first.
   */
  public function operationHistory(NodeInterface $node): array {
    $storage = $this->entityTypeManager->getStorage('node');
    $users = $this->entityTypeManager->getStorage('user');
    $history = [];
    foreach (array_reverse($storage->revisionIds($node)) as $vid) {
      /** @var \Drupal\node\NodeInterface $revision */
      $revision = $storage->loadRevision($vid);
      if (!$revision) {
        continue;
      }
      $author = $revision->getRevisionUser() ?: $users->load($revision->getOwnerId());
      $history[] = [
        'vid' => (int) $vid,
        'date' => date('c', (int) $revision->getRevisionCreationTime()),
        'user' => $author ? $author->getDisplayName() : '—',
        'message' => (string) $revision->getRevisionLogMessage(),
        'amount' => $revision->hasField('field_amount') ? (int) $revision->get('field_amount')->value : NULL,
        'mouvement' => $revision->hasField('field_mouvement_argent') ? (string) $revision->get('field_mouvement_argent')->value : NULL,
      ];
    }
    return $history;
  }

}
