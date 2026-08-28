<?php

declare(strict_types=1);

namespace Drupal\ai_content_validation;

/**
 * Deterministic repair of almost-valid JSON emitted by a language model.
 *
 * Models occasionally drop a trailing closing brace, wrap the JSON in code
 * fences or prepend prose. The tolerant mode of the json_to_data node only
 * strips fences, so an unbalanced object still parses to NULL and fails
 * the run. This repairer fixes exactly the mechanical defects (prefix
 * text, fences, unterminated string, missing closers) — it never invents
 * content, and returns NULL when the text is not one JSON object at all.
 */
final class JsonRepair {

  /**
   * Parses a JSON object string, repairing mechanical truncation defects.
   *
   * @param string $raw
   *   The raw model output.
   *
   * @return array<string, mixed>|null
   *   The decoded object, or NULL when no repair produces valid JSON.
   */
  public static function parse(string $raw): ?array {
    $decoded = json_decode($raw, TRUE);
    if (is_array($decoded)) {
      return $decoded;
    }
    // Cut everything before the first opening brace (prose, code fences).
    $start = strpos($raw, '{');
    if ($start === FALSE) {
      return NULL;
    }
    $text = substr($raw, $start);
    // String-aware scan: track open objects/arrays and cut at the point
    // where the root object closes, ignoring braces inside strings.
    $stack = [];
    $in_string = FALSE;
    $escaped = FALSE;
    $end = NULL;
    $length = strlen($text);
    for ($i = 0; $i < $length; $i++) {
      $char = $text[$i];
      if ($in_string) {
        if ($escaped) {
          $escaped = FALSE;
        }
        elseif ($char === '\\') {
          $escaped = TRUE;
        }
        elseif ($char === '"') {
          $in_string = FALSE;
        }
        continue;
      }
      switch ($char) {
        case '"':
          $in_string = TRUE;
          break;

        case '{':
        case '[':
          $stack[] = $char;
          break;

        case '}':
        case ']':
          array_pop($stack);
          if ($stack === []) {
            $end = $i;
          }
          break;
      }
      if ($end !== NULL) {
        break;
      }
    }
    if ($end !== NULL) {
      // Root closed — drop any trailing garbage after it.
      $text = substr($text, 0, $end + 1);
    }
    else {
      // Truncated output: close an open string, then every open scope.
      if ($in_string) {
        $text .= '"';
      }
      // A truncation right after a comma or colon would leave invalid
      // syntax before the closers — trim dangling separators first.
      $text = rtrim($text, ", \t\n\r:");
      foreach (array_reverse($stack) as $open) {
        $text .= $open === '{' ? '}' : ']';
      }
    }
    $decoded = json_decode($text, TRUE);
    return is_array($decoded) ? $decoded : NULL;
  }

}
