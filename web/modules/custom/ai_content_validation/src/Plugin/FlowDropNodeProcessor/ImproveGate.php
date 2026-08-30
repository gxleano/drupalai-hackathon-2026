<?php

declare(strict_types=1);

namespace Drupal\ai_content_validation\Plugin\FlowDropNodeProcessor;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Entity\RevisionableStorageInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ai_content_validation\JsonRepair;
use Drupal\ai_content_validation\ValidatedFields;
use Drupal\ai_content_validation\ValidationScorer;
use Drupal\flowdrop\Attribute\FlowDropNodeProcessor;
use Drupal\flowdrop\DTO\ParameterBag;
use Drupal\flowdrop\DTO\ParameterBagInterface;
use Drupal\flowdrop\Exception\EntityProcessingException;
use Drupal\flowdrop\Plugin\FlowDropNodeProcessor\AbstractFlowDropNodeProcessor;
use Drupal\flowdrop\Service\FlowDropNodeProcessorPluginManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Gates an AI Improve proposal on a re-validation of its own candidate.
 *
 * An AI rewrite that lowers the quality score is worse than no rewrite at
 * all, and the prompt-level NON-REGRESSION RULE is only a first line of
 * defence — a prompt cannot guarantee anything. This node enforces the
 * guarantee in code:
 *
 * 1. It reads the baseline: the score of the current validation report for
 *    the node, i.e. exactly the number the editor sees in the AI Validation
 *    page header. Reaching this node with no report is a bug — the Improve
 *    content button only appears when a report exists — so it fails loudly
 *    rather than inventing a baseline.
 * 2. It builds the candidate content the suggestions would produce, using
 *    the same merge semantics the Apply button uses, and serialises it with
 *    `ai_content_validation_candidate_json` into the exact JSON the
 *    validator normally receives.
 * 3. It re-scores that candidate with the validator's OWN chat node — the
 *    model, temperature, token budget and system prompt are read out of the
 *    `content_validation_fixer` workflow configuration, so there is one
 *    prompt, not a paraphrase — and derives the number through
 *    ValidationScorer, the same verdict-to-points path the header score
 *    comes from.
 * 4. It compares the two totals in PHP. The model is never asked whether it
 *    improved anything. Ties pass: a candidate that fixes a named finding
 *    without moving the number is still worth offering.
 *
 * On acceptance the suggestions pass through untouched. On rejection the
 * suggestions are stripped and the payload is marked as a rejected
 * improvement — a third kind of item that is neither a score report nor a
 * proposal, and that ValidationSave therefore never lets supersede
 * anything. Both scores are recorded on every run, accepted or rejected:
 * that is the only way to notice the improve prompt has rotted and is being
 * rejected nearly every time.
 */
#[FlowDropNodeProcessor(
  id: 'ai_content_validation_improve_gate',
  label: new TranslatableMarkup('Gate Improvement'),
  description: 'Re-validates an AI Improve proposal and discards it when it would lower the quality score',
  version: '1.0.0',
)]
final class ImproveGate extends AbstractFlowDropNodeProcessor {

  /**
   * Outcome marker for a proposal that scored at least the baseline.
   */
  public const OUTCOME_ACCEPTED = 'improve_accepted';

  /**
   * Outcome marker for a proposal discarded for scoring lower.
   */
  public const OUTCOME_REJECTED = 'improve_rejected';

  /**
   * Outcome marker for an improve run that proposed nothing at all.
   */
  public const OUTCOME_NO_SUGGESTIONS = 'improve_no_suggestions';

  /**
   * The validation workflow whose report is the default baseline.
   */
  private const DEFAULT_VALIDATION_WORKFLOW = 'content_validation_fixer';

  /**
   * The node type of the chat node that carries the validator prompt.
   */
  private const CHAT_NODE_TYPE = 'flowdrop_ai_provider_chat';

  /**
   * The node type of the JSON parser the validator chain uses.
   */
  private const PARSE_NODE_TYPE = 'json_to_data';

  /**
   * The plugin that serialises candidate values as validator-ready JSON.
   */
  private const CANDIDATE_JSON_PLUGIN = 'ai_content_validation:ai_content_validation_candidate_json';

  /**
   * Constructs an ImproveGate object.
   *
   * @param array<string, mixed> $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\flowdrop\Service\FlowDropNodeProcessorPluginManager $nodeProcessorManager
   *   The node processor manager, used to run the validator's own chat and
   *   parse nodes rather than a copy of them.
   * @param \Drupal\Core\Logger\LoggerChannelInterface $logger
   *   The module's logger, where every gate decision is recorded.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly FlowDropNodeProcessorPluginManager $nodeProcessorManager,
    private readonly LoggerChannelInterface $logger,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The service container.
   * @param array<string, mixed> $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition.
   */
  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition,
  ): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('flowdrop.node_processor_plugin_manager'),
      $container->get('logger.factory')->get('ai_content_validation'),
    );
  }

  /**
   * Decides whether a candidate score may replace a baseline score.
   *
   * Ties pass, deliberately: a candidate that scores equal to the baseline
   * may still fix a named finding without moving the number. Tighten this
   * to a strict improvement only with evidence.
   *
   * @param int $baseline
   *   The score of the content as it stands.
   * @param int $candidate
   *   The score the proposed content achieved.
   *
   * @return bool
   *   TRUE when the proposal may be offered to the editor.
   */
  public static function accepts(int $baseline, int $candidate): bool {
    return $candidate >= $baseline;
  }

  /**
   * {@inheritdoc}
   */
  public function getParameterSchema(): array {
    return [
      'type' => 'object',
      'properties' => [
        'data' => [
          'type' => 'object',
          'title' => 'Improver values',
          'description' => 'The parsed improver output: the validation item values carrying field_validation_result with the proposed suggestions.',
          'additionalProperties' => TRUE,
          'required' => TRUE,
        ],
        'validation_workflow' => [
          'type' => 'string',
          'title' => 'Validation workflow',
          'description' => 'The workflow whose report is the baseline and whose chat node supplies the validator prompt. Must be the workflow the editor\'s score comes from.',
          'default' => self::DEFAULT_VALIDATION_WORKFLOW,
        ],
        'findings' => [
          'type' => 'string',
          'title' => 'Findings',
          'description' => 'The findings string the improver received. When it carries an [only_field:...] marker (per-field "Fix with AI"), suggestions for any other field are discarded deterministically — prompt obedience is not trusted.',
          'default' => '',
        ],
        'json' => [
          'type' => 'string',
          'title' => 'Raw response',
          'description' => 'The raw improver response, used to repair-parse when the upstream JSON parse delivered nothing (e.g. a dropped closing brace).',
          'default' => '',
        ],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getOutputSchema(): array {
    return [
      'type' => 'object',
      'properties' => [
        'data' => [
          'type' => 'object',
          'description' => 'The gated validation item values, ready for the normalize node.',
          'additionalProperties' => TRUE,
        ],
        'accepted' => [
          'type' => 'boolean',
          'description' => 'Whether the proposal survived the gate.',
        ],
        'baseline_score' => [
          'type' => 'integer',
          'description' => 'The score of the content as it stands.',
        ],
        'candidate_score' => [
          'type' => 'integer',
          'description' => 'The score the proposed content achieved.',
        ],
        'outcome' => [
          'type' => 'string',
          'description' => 'improve_accepted, improve_rejected or improve_no_suggestions.',
        ],
        'reason' => [
          'type' => 'string',
          'description' => 'Why the gate decided the way it did.',
        ],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   *
   * @throws \InvalidArgumentException
   *   When the improver output carries no result payload to gate.
   * @throws \Drupal\flowdrop\Exception\EntityProcessingException
   *   When the node cannot be resolved, when no baseline report exists for
   *   it (the Improve button is only offered when one does, so this is a
   *   bug rather than a state to paper over), or when the candidate
   *   re-validation produced nothing scoreable.
   */
  public function process(ParameterBagInterface $params): array {
    $data = $params->getArray('data');
    // Upstream json_to_data delivers NULL when the improver's JSON is
    // mechanically broken (typically a dropped final brace); the raw
    // response is wired in as a fallback and repaired deterministically.
    if ($data === []) {
      $data = JsonRepair::parse($params->getString('json')) ?? [];
    }
    if ($data === []) {
      throw new EntityProcessingException('The improver response could not be parsed as JSON, even after repair.');
    }
    $workflow_id = trim($params->getString('validation_workflow', self::DEFAULT_VALIDATION_WORKFLOW));
    if ($workflow_id === '') {
      $workflow_id = self::DEFAULT_VALIDATION_WORKFLOW;
    }

    $result = $this->resultPayload($data);
    $nid = $this->referencedNodeId($data['field_content_revision'] ?? NULL);
    $report = $this->baselineReport($nid, $workflow_id);
    $baseline = $this->reportScore($report);
    $suggestions = $this->improverSuggestions($result);

    // A per-field "Fix with AI" click stamps [only_field:x] into the
    // findings. The prompt already tells the model to emit only that
    // field, but the guarantee is enforced HERE: any stray suggestion for
    // another field is dropped before it is scored, stored or offered.
    $only_field = NULL;
    if (preg_match('/\[only_field:([a-z0-9_]+)\]/', $params->getString('findings', ''), $m)) {
      $only_field = $m[1];
      $suggestions = array_values(array_filter(
        $suggestions,
        fn (array $sug) => ($sug['field'] ?? NULL) === $only_field,
      ));
    }

    // The baseline score describes one specific revision, so the candidate
    // must differ from that revision by the suggestions and nothing else.
    // Scoring against any other revision would fold unrelated edits into
    // the comparison.
    $vid = (int) ($report->get('field_content_revision')->target_revision_id ?? 0);
    $base = $this->loadRevision($nid, $vid);
    $candidate_values = $suggestions === [] ? [] : $this->candidateValues($base, $suggestions);

    if ($candidate_values === []) {
      // Nothing would change, so the candidate content IS the current
      // content and its score is the baseline by definition — no model
      // call can tell us anything new. There is still nothing to offer.
      return $this->decide(
        $data,
        $result,
        $suggestions,
        $baseline,
        $baseline,
        self::OUTCOME_NO_SUGGESTIONS,
        'The improver proposed no applicable change, so there is nothing to offer.',
        $nid,
      );
    }

    // A single-field fix is reviewed by the editor before anything is
    // applied (inline diff with Accept/Reject), and the post-apply
    // re-validation still measures the real result. Re-scoring the whole
    // article for a one-field change proved to be noise, not signal: the
    // model's per-guideline verdicts wobble across near-identical inputs
    // (measured: the same candidate scored 78, 78 and 88 at temperature
    // 0, flipping guidelines the changed field cannot affect), so the
    // comparison rejected almost every legitimate fix. The score gate
    // therefore only arbitrates whole-article improve runs, where a
    // regression can hide inside a large rewrite no human reads in full.
    if ($only_field !== NULL) {
      return $this->decide(
        $data,
        $result,
        $suggestions,
        $baseline,
        $baseline,
        self::OUTCOME_ACCEPTED,
        sprintf('Single-field fix for "%s" offered for editor review; the post-apply validation measures the result.', $only_field),
        $nid,
      );
    }

    $candidate = $this->scoreCandidate($workflow_id, $nid, $vid, $candidate_values);
    $accepted = self::accepts($baseline, $candidate);

    return $this->decide(
      $data,
      $result,
      $suggestions,
      $baseline,
      $candidate,
      $accepted ? self::OUTCOME_ACCEPTED : self::OUTCOME_REJECTED,
      $accepted
        ? sprintf('The proposed content scored %d/100 against the current %d/100.', $candidate, $baseline)
        : sprintf('The proposed content scored %d/100, below the current %d/100, so it was discarded instead of offered.', $candidate, $baseline),
      $nid,
    );
  }

  /**
   * Records the decision on the payload and returns the node output.
   *
   * @param array<string, mixed> $data
   *   The improver's validation item values.
   * @param array<string, mixed> $result
   *   The decoded field_validation_result payload.
   * @param list<array<string, mixed>> $suggestions
   *   The improver's suggestions.
   * @param int $baseline
   *   The baseline score.
   * @param int $candidate
   *   The candidate score.
   * @param string $outcome
   *   One of the OUTCOME_* markers.
   * @param string $reason
   *   The human-readable reason for the decision.
   * @param int $nid
   *   The node the decision is about, for the log entry.
   *
   * @return array{data: array<string, mixed>, accepted: bool, baseline_score: int, candidate_score: int, outcome: string, reason: string}
   *   The node output.
   */
  private function decide(
    array $data,
    array $result,
    array $suggestions,
    int $baseline,
    int $candidate,
    string $outcome,
    string $reason,
    int $nid,
  ): array {
    $accepted = $outcome === self::OUTCOME_ACCEPTED;

    // Both scores are recorded on every run. Without them a rotted
    // improve prompt would be rejected silently and forever.
    $result['outcome'] = $outcome;
    $result['baseline_score'] = $baseline;
    $result['candidate_score'] = $candidate;
    $result['gate_reason'] = $reason;
    // Only a validation report may carry a number here; a proposal that
    // did would be picked up as a score for the node's current content.
    $result['score'] = NULL;

    if ($accepted) {
      $result['suggestions'] = $suggestions;
    }
    else {
      // Emptied so the review form offers nothing to apply, but kept
      // alongside for inspection: a rejection whose content vanishes
      // cannot be diagnosed.
      $result['suggestions'] = [];
      $result['rejected_suggestions'] = $suggestions;
      $summary = is_scalar($result['summary'] ?? NULL) ? trim((string) $result['summary']) : '';
      $result['summary'] = $summary === ''
        ? $reason
        : $reason . ' The improver reported: ' . $summary;
    }

    $data['field_validation_result'] = $result;

    $this->logger->info('AI Improve gate on node @nid: @outcome (baseline @baseline/100, candidate @candidate/100). @reason', [
      '@nid' => $nid,
      '@outcome' => $outcome,
      '@baseline' => $baseline,
      '@candidate' => $candidate,
      '@reason' => $reason,
    ]);

    return [
      'data' => $data,
      'accepted' => $accepted,
      'baseline_score' => $baseline,
      'candidate_score' => $candidate,
      'outcome' => $outcome,
      'reason' => $reason,
    ];
  }

  /**
   * Extracts the result payload from the improver's values.
   *
   * @param array<string, mixed> $data
   *   The improver's validation item values.
   *
   * @return array<string, mixed>
   *   The decoded result payload.
   *
   * @throws \InvalidArgumentException
   *   When there is no result object to gate.
   */
  private function resultPayload(array $data): array {
    $result = $data['field_validation_result'] ?? NULL;
    if (is_string($result)) {
      $result = json_decode($result, TRUE);
    }
    if (!is_array($result)) {
      throw new \InvalidArgumentException('The improver output carries no field_validation_result object to gate.');
    }
    return $result;
  }

  /**
   * Reads the improver's suggestion list.
   *
   * @param array<string, mixed> $result
   *   The result payload.
   *
   * @return list<array<string, mixed>>
   *   The suggestions, re-indexed, with non-array entries dropped.
   */
  private function improverSuggestions(array $result): array {
    $suggestions = $result['suggestions'] ?? NULL;
    if (!is_array($suggestions)) {
      return [];
    }
    return array_values(array_filter($suggestions, 'is_array'));
  }

  /**
   * Resolves the node ID the improver's values point at.
   *
   * @param mixed $reference
   *   The field_content_revision value: a map with target_id and
   *   target_revision_id, or a list of such maps.
   *
   * @return int
   *   The node ID.
   *
   * @throws \Drupal\flowdrop\Exception\EntityProcessingException
   *   When no node ID can be resolved.
   */
  private function referencedNodeId(mixed $reference): int {
    if (is_array($reference) && isset($reference[0]) && is_array($reference[0])) {
      $reference = $reference[0];
    }
    $nid = is_array($reference) ? (int) ($reference['target_id'] ?? 0) : 0;
    if ($nid === 0) {
      throw new EntityProcessingException('The improver output references no node, so its proposal cannot be gated.');
    }
    return $nid;
  }

  /**
   * Loads the current validation report the baseline score comes from.
   *
   * This mirrors the rule the AI Validation page header uses: the newest
   * still-current (pending or done) report of the validation workflow for
   * this node. Superseded and ignored items are history and never speak
   * for the current content.
   *
   * @param int $nid
   *   The node ID.
   * @param string $workflow_id
   *   The validation workflow.
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface
   *   The baseline report.
   *
   * @throws \Drupal\flowdrop\Exception\EntityProcessingException
   *   When no report with a numeric score exists. The Improve content
   *   button is only shown when one does, so this is a bug, not a state
   *   to paper over with a fabricated baseline.
   */
  private function baselineReport(int $nid, string $workflow_id): ContentEntityInterface {
    $storage = $this->entityTypeManager->getStorage('ai_content_validation_item');
    // Access intent: accessCheck(FALSE). This is an internal consistency
    // lookup inside a workflow run that may legitimately have no
    // interactive user (entity-save or cron triggers), and its outcome
    // discloses no item content — it only decides whether a proposal may
    // be offered at all.
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_content_revision.target_id', $nid)
      ->condition('field_flowdrop_workflow', $workflow_id)
      ->condition('field_validation_status', ['pending', 'done'], 'IN')
      ->sort('created', 'DESC')
      ->range(0, 10)
      ->execute();

    $items = $storage->loadMultiple($ids);
    // $ids is ordered newest first; loadMultiple() is not.
    foreach ($ids as $id) {
      $item = $items[$id] ?? NULL;
      if (!$item instanceof ContentEntityInterface) {
        continue;
      }
      $parsed = $this->decodeResult((string) ($item->get('field_validation_result')->value ?? ''));
      if (($parsed['suggestions'] ?? []) === [] && is_numeric($parsed['score'] ?? NULL)) {
        return $item;
      }
    }

    throw new EntityProcessingException(sprintf('No current validation report with a score exists for node %d in workflow "%s", so there is no baseline to gate the improvement against.', $nid, $workflow_id));
  }

  /**
   * Reads the numeric score off a report.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $report
   *   The baseline report.
   *
   * @return int
   *   The baseline score.
   */
  private function reportScore(ContentEntityInterface $report): int {
    $parsed = $this->decodeResult((string) ($report->get('field_validation_result')->value ?? ''));
    return (int) $parsed['score'];
  }

  /**
   * Decodes a stored validation result.
   *
   * Legacy items can be double-encoded (a JSON string containing JSON),
   * so decode twice before giving up.
   *
   * @param string $raw
   *   The stored field value.
   *
   * @return array<string, mixed>
   *   The decoded result, or an empty array when the value is not JSON.
   */
  private function decodeResult(string $raw): array {
    $decoded = json_decode($raw, TRUE);
    if (is_string($decoded)) {
      $decoded = json_decode($decoded, TRUE);
    }
    return is_array($decoded) ? $decoded : [];
  }

  /**
   * Loads the node revision the baseline score was formed on.
   *
   * @param int $nid
   *   The node ID.
   * @param int $vid
   *   The revision ID, or 0 for the default revision.
   *
   * @return \Drupal\Core\Entity\FieldableEntityInterface
   *   The base revision.
   *
   * @throws \Drupal\flowdrop\Exception\EntityProcessingException
   *   When neither the revision nor the node can be loaded.
   */
  private function loadRevision(int $nid, int $vid): FieldableEntityInterface {
    $storage = $this->entityTypeManager->getStorage('node');
    $revision = NULL;
    if ($vid !== 0 && $storage instanceof RevisionableStorageInterface) {
      $revision = $storage->loadRevision($vid);
    }
    // A revision id that belongs to another node would describe foreign
    // content, so it is discarded in favour of the node itself.
    if (!$revision instanceof FieldableEntityInterface || (int) $revision->id() !== $nid) {
      $revision = $storage->load($nid);
    }
    if (!$revision instanceof FieldableEntityInterface) {
      throw new EntityProcessingException(sprintf('Node %d could not be loaded, so its improvement cannot be gated.', $nid));
    }
    return $revision;
  }

  /**
   * Builds the field values the suggestions would actually apply.
   *
   * The shapes and the merge semantics mirror
   * AiReviewForm::applyToNode()/mergeValue()/rawValue(): the gate must
   * score the content the editor would really get, not an idealised
   * version of it. Suggestions the Apply path would skip — an unknown
   * field, a field whose main property is not "value", an empty
   * replacement — are skipped here too. Keep the two in step.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $base
   *   The revision the suggestions would be applied to.
   * @param list<array<string, mixed>> $suggestions
   *   The improver's suggestions, each with `field`, `current` and
   *   `suggested`.
   *
   * @return array<string, array<string, mixed>>
   *   Candidate values keyed by field name, in field-item shape so no
   *   property (a text format, for instance) is silently dropped.
   */
  private function candidateValues(FieldableEntityInterface $base, array $suggestions): array {
    $values = [];
    foreach ($suggestions as $suggestion) {
      $field = (string) ($suggestion['field'] ?? '');
      if ($field === '' || !$base->hasField($field)) {
        continue;
      }
      // A media suggestion carries the new ALT TEXT: keep the current
      // targets and override target_alt, so the candidate is scored on
      // the alt the fix would produce — the media entity is untouched.
      if (ValidatedFields::isMediaField($base, $field)) {
        $alt = trim($this->plainText($this->rawValue($suggestion['suggested'] ?? '')));
        $targets = array_map(
          static fn (array $item): array => array_intersect_key($item, ['target_id' => TRUE]),
          array_filter($base->get($field)->getValue(), 'is_array'),
        );
        if ($alt !== '' && $targets !== []) {
          $targets[array_key_first($targets)]['target_alt'] = $alt;
          $values[$field] = array_values($targets);
        }
        continue;
      }
      // A tags suggestion carries term NAMES: serialize them as
      // label-only reference items so the candidate is scored on the
      // labels, exactly what the validator reads — no term is created.
      if (ValidatedFields::isTagsField($base, $field)) {
        $names = ValidatedFields::parseTagNames($this->rawValue($suggestion['suggested'] ?? ''));
        if ($names !== []) {
          $values[$field] = array_map(
            static fn (string $name): array => ['target_label' => $name],
            $names,
          );
        }
        continue;
      }
      $item = $base->get($field);
      if ($item->getFieldDefinition()->getFieldStorageDefinition()->getMainPropertyName() !== 'value') {
        continue;
      }
      $suggested = $this->rawValue($suggestion['suggested'] ?? '');
      // An empty replacement is a model mistake (the skipped-field
      // contract), never an instruction to blank a field, and the Apply
      // path drops it too.
      if ($this->plainText($suggested) === '') {
        continue;
      }
      $existing = $item->first()?->getValue() ?? [];
      $existing['value'] = $this->mergeValue(
        (string) ($existing['value'] ?? ''),
        $suggested,
        $this->rawValue($suggestion['current'] ?? ''),
      );
      $values[$field] = $existing;
    }
    return $values;
  }

  /**
   * Scores a candidate with the validator's own chat node.
   *
   * @param string $workflow_id
   *   The validation workflow supplying the prompt.
   * @param int $nid
   *   The node ID.
   * @param int $vid
   *   The base revision ID.
   * @param array<string, array<string, mixed>> $candidate_values
   *   The candidate field values.
   *
   * @return int
   *   The candidate's total score, on the same 0-100 scale as the
   *   baseline.
   *
   * @throws \Drupal\flowdrop\Exception\EntityProcessingException
   *   When the re-validation produced no scoreable verdicts.
   */
  private function scoreCandidate(string $workflow_id, int $nid, int $vid, array $candidate_values): int {
    $serializer = $this->nodeProcessorManager->createInstance(self::CANDIDATE_JSON_PLUGIN);
    $serialized = $serializer->process(new ParameterBag([
      'entity_id' => (string) $nid,
      'revision_id' => $vid === 0 ? '' : (string) $vid,
      'candidate_values' => $candidate_values,
    ]));

    // The live validation run prefixes the assessed-field contract to the
    // payload (ValidationPayload); the gate assembles its own message, so
    // it must prefix the identical header or the candidate would be
    // scored with a prompt whose field contract it never received.
    $node = $this->entityTypeManager->getStorage('node')->load($nid);
    $header = ValidationPayload::header(
      $node instanceof FieldableEntityInterface ? $node : NULL,
      is_array($serialized['data'] ?? NULL) ? $serialized['data'] : [],
    );
    $response = $this->runWorkflowNode($workflow_id, self::CHAT_NODE_TYPE, [
      'message' => $header . "\n" . (string) ($serialized['json'] ?? ''),
    ]);
    $parsed = $this->runWorkflowNode($workflow_id, self::PARSE_NODE_TYPE, [
      'json' => (string) ($response['response'] ?? ''),
    ]);

    $data = $parsed['data'] ?? NULL;
    // Repair mechanically broken model JSON (dropped closing brace)
    // before giving up on the candidate score.
    if (!is_array($data)) {
      $data = JsonRepair::parse((string) ($response['response'] ?? ''));
    }
    $result = is_array($data) ? ($data['field_validation_result'] ?? NULL) : NULL;
    if (!is_array($result)) {
      throw new EntityProcessingException('The candidate re-validation returned no result object, so the improvement cannot be gated.');
    }
    $scored = ValidationScorer::applyDerivedScore($result);
    if (!is_numeric($scored['score'] ?? NULL)) {
      throw new EntityProcessingException('The candidate re-validation returned no scoreable verdicts, so the improvement cannot be gated.');
    }
    return (int) $scored['score'];
  }

  /**
   * Runs a node of another workflow with that node's own configuration.
   *
   * This is what makes the gate score the candidate with the SAME
   * validator prompt the editor's score comes from: the model,
   * temperature, token budget and system prompt are read out of the
   * validation workflow's chat node, and the node type's own defaults fill
   * in whatever the stored (slimmed) node config omitted. Nothing about
   * the prompt is duplicated here, so it cannot drift.
   *
   * @param string $workflow_id
   *   The workflow to borrow the node from.
   * @param string $node_type_id
   *   The node type to look for.
   * @param array<string, mixed> $inputs
   *   Runtime inputs, applied on top of the node's configuration.
   *
   * @return array<string, mixed>
   *   The node's output.
   *
   * @throws \Drupal\flowdrop\Exception\EntityProcessingException
   *   When the workflow, the node or its node type cannot be resolved.
   */
  private function runWorkflowNode(string $workflow_id, string $node_type_id, array $inputs): array {
    $workflow = $this->entityTypeManager->getStorage('flowdrop_workflow')->load($workflow_id);
    if ($workflow === NULL) {
      throw new EntityProcessingException(sprintf('Validation workflow "%s" does not exist, so the candidate cannot be scored with the editor\'s prompt.', $workflow_id));
    }

    $config = NULL;
    foreach ($workflow->getNodes() as $node) {
      if (($node['data']['metadata']['node_type_id'] ?? '') === $node_type_id) {
        $config = is_array($node['data']['config'] ?? NULL) ? $node['data']['config'] : [];
        break;
      }
    }
    if ($config === NULL) {
      throw new EntityProcessingException(sprintf('Workflow "%s" has no "%s" node to borrow, so the candidate cannot be scored with the editor\'s prompt.', $workflow_id, $node_type_id));
    }

    $node_type = $this->entityTypeManager->getStorage('flowdrop_node_type')->load($node_type_id);
    if ($node_type === NULL) {
      throw new EntityProcessingException(sprintf('Node type "%s" does not exist.', $node_type_id));
    }

    // Stored workflow nodes are slimmed: config equal to the node type's
    // default is dropped. Layering the defaults back underneath is what
    // reproduces the parameters the workflow runtime would have resolved.
    $defaults = [];
    foreach ($node_type->getParameters() as $name => $definition) {
      if (is_array($definition) && array_key_exists('default', $definition)) {
        $defaults[$name] = $definition['default'];
      }
    }

    $processor = $this->nodeProcessorManager->createInstance($node_type->getExecutorPlugin());
    return $processor->process(new ParameterBag($inputs + $config + $defaults));
  }

  /**
   * Normalizes a model-sent value (scalar or array) to a string.
   *
   * Mirrors AiReviewForm::rawValue(): arrays are JSON-encoded (never cast,
   * which would yield the literal "Array"), and key/value-pair lists are
   * normalized to the stored object shape.
   *
   * @param mixed $value
   *   The value as the model sent it.
   *
   * @return string
   *   The value as it would be stored.
   */
  private function rawValue(mixed $value): string {
    return $this->normalizeKeyValueJson(is_scalar($value)
      ? (string) $value
      : (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
  }

  /**
   * Converts a JSON list of {key, value} pairs into a plain JSON object.
   *
   * Mirrors AiReviewForm::normalizeKeyValueJson(): the improve model
   * sometimes serializes meta tags as [{"key":…,"value":…}, …] instead of
   * {"…":"…"}. The stored metatag format is the object shape, so the
   * candidate must be scored in that shape too. Anything else passes
   * through unchanged.
   *
   * @param string $raw
   *   The value as the model sent it.
   *
   * @return string
   *   The normalized value.
   */
  private function normalizeKeyValueJson(string $raw): string {
    $trimmed = ltrim($raw);
    if (!str_starts_with($trimmed, '[')) {
      return $raw;
    }
    $decoded = json_decode($trimmed, TRUE);
    if (!is_array($decoded) || $decoded === []) {
      return $raw;
    }
    $assoc = [];
    foreach ($decoded as $item) {
      if (!is_array($item) || !is_scalar($item['key'] ?? NULL) || !is_scalar($item['value'] ?? NULL)) {
        return $raw;
      }
      $assoc[(string) $item['key']] = $item['value'];
    }
    return (string) json_encode($assoc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  }

  /**
   * Merges a suggested value into the stored one.
   *
   * Mirrors AiReviewForm::mergeValue(): fragment replacement when the
   * model's "current" text is found inside the stored value, full
   * replacement otherwise.
   *
   * @param string $stored
   *   The value currently stored on the node.
   * @param string $suggested
   *   The value the model proposes.
   * @param string $current
   *   The value the model claims is currently stored.
   *
   * @return string
   *   The merged value.
   */
  private function mergeValue(string $stored, string $suggested, string $current): string {
    if ($current !== '' && $current !== $stored && str_contains($stored, $current)) {
      return str_replace($current, $suggested, $stored);
    }
    return $suggested;
  }

  /**
   * Reduces an HTML value to readable plain text.
   *
   * Mirrors AiReviewForm::plainText(), used only to tell an effectively
   * empty replacement from a real one.
   *
   * @param string $text
   *   The value to reduce.
   *
   * @return string
   *   The plain text.
   */
  private function plainText(string $text): string {
    return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5)) ?? $text);
  }

}
