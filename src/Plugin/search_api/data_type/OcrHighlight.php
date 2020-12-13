<?php


namespace Drupal\strawberryfield\Plugin\search_api\data_type;
use Drupal\search_api\Plugin\search_api\data_type\TextDataType;

/**
 * Provides a full text data type which omit norms.
 *
 * @SearchApiDataType(
 *   id = "solr_text_custom:ocr_highlight",
 *   label = @Translation("OCR Highlight using "),
 *   description = @Translation("Custom Full text field with OCR/coordinates Highligth."),
 *   fallback_type = "text",
 *   prefix = "ocr"
 * )
 */
class OcrHighlight extends TextDataType{

}
