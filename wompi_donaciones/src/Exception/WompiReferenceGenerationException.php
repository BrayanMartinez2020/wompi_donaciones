<?php

namespace Drupal\wompi_donaciones\Exception;

/**
 * Se lanza cuando no fue posible generar una referencia única de Wompi
 * tras varios intentos (colisiones repetidas contra la restricción UNIQUE
 * de la columna "reference").
 */
class WompiReferenceGenerationException extends \RuntimeException {

}
