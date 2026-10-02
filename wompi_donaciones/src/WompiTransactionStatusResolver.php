<?php

namespace Drupal\wompi_donaciones;

use GuzzleHttp\Exception\GuzzleException;

/**
 * Resuelve el estado real de una donación para la pantalla final
 * "¡Gracias por tu aporte!" (CA06-CA09 de HU001.1).
 *
 * Primero mira si el webhook (WompiWebhookController) ya dejó un estado
 * final guardado en wompi_donaciones_transaction. Si no ha llegado
 * todavía (o el estado local sigue PENDIENTE), consulta directamente la
 * API de Wompi para tener el dato real - así el donante ve el resultado
 * correcto aunque haya cerrado la pestaña de Wompi antes de tiempo, y
 * sin depender únicamente de que el webhook ya haya llegado.
 *
 * Nunca le dice al donante que su pago quedó aprobado sin haberlo
 * confirmado: cualquier caso dudoso (no se encuentra la transacción, la
 * API de Wompi no responde, etc.) se trata como PENDIENTE.
 */
class WompiTransactionStatusResolver {

  const STATE_PREFIX = 'wompi_donaciones.';

  /**
   * Debe coincidir con WompiWebhookController::MAPA_ESTADOS: así se
   * guarda el estado en la base de datos (más detallado que los tres
   * resultados que ve el donante en pantalla).
   */
  const MAPA_ESTADOS_WOMPI = [
    'APPROVED' => 'APROBADA',
    'DECLINED' => 'DECLINADA',
    'VOIDED' => 'DECLINADA',
    'ERROR' => 'ERROR',
    'PENDING' => 'PENDIENTE',
  ];

  /**
   * Los tres resultados que describe la HU (CA07, CA08, CA09).
   * DECLINADA y ERROR se muestran igual, como "CANCELADA".
   */
  const MENSAJES_DONANTE = [
    'APROBADA' => NULL,
    'CANCELADA' => 'Tu donación no se pudo completar. No te preocupes, no se realizó ningún cobro. Cuando quieras puedes realizar una nueva donación, tu apoyo hace la diferencia.',
    'PENDIENTE' => 'Estamos procesando tu donación. Este proceso puede tardar unos minutos; apenas se confirme te lo haremos saber. ¡Gracias por tu apoyo!',
  ];

  /**
   * @return array{estado: string, mensaje: string|null}
   */
  public function resolver($wompi_transaction_id) {
    $estado_guardado = $this->buscarEstadoLocal($wompi_transaction_id);

    if ($estado_guardado === NULL || $estado_guardado === 'PENDIENTE') {
      $datos_wompi = $this->consultarWompi($wompi_transaction_id);
      if ($datos_wompi !== NULL) {
        $estado_nuevo = self::MAPA_ESTADOS_WOMPI[$datos_wompi['status']] ?? NULL;
        if ($estado_nuevo !== NULL && !empty($datos_wompi['reference'])) {
          $this->actualizarEstadoLocal($wompi_transaction_id, $datos_wompi['reference'], $estado_nuevo);
          $estado_guardado = $estado_nuevo;
        }
      }
    }

    return $this->responder($estado_guardado);
  }

  /**
   * Busca si ya tenemos un estado guardado localmente (normalmente
   * puesto ahí por el webhook) para este id de transacción de Wompi.
   */
  protected function buscarEstadoLocal($wompi_transaction_id) {
    $valor = \Drupal::database()->select('wompi_donaciones_transaction', 't')
      ->fields('t', ['status'])
      ->condition('wompi_transaction_id', $wompi_transaction_id)
      ->execute()
      ->fetchField();
    return $valor === FALSE ? NULL : $valor;
  }

  /**
   * Traduce el estado interno (guardado en BD) a lo que necesita ver el
   * donante: el resultado real, o PENDIENTE si no se pudo confirmar.
   */
  protected function responder($estado_guardado) {
    $estado_vista = 'PENDIENTE';
    if ($estado_guardado === 'APROBADA') {
      $estado_vista = 'APROBADA';
    }
    elseif (in_array($estado_guardado, ['DECLINADA', 'ERROR'], TRUE)) {
      $estado_vista = 'CANCELADA';
    }

    return [
      'estado' => $estado_vista,
      'mensaje' => self::MENSAJES_DONANTE[$estado_vista],
    ];
  }

  /**
   * Consulta el estado real de la transacción directamente en la API de
   * Wompi, usando la llave pública (así lo documenta Wompi para
   * consultar el estado de una transacción).
   *
   * @return array{status: string, reference: string}|null
   */
  protected function consultarWompi($wompi_transaction_id) {
    $state = \Drupal::state();
    $public_key = $state->get(self::STATE_PREFIX . 'public_key');
    $resultado = NULL;

    if (!empty($public_key)) {
      $environment = $state->get(self::STATE_PREFIX . 'environment', 'sandbox');
      $dominio = $environment === 'production' ? 'https://production.wompi.co' : 'https://sandbox.wompi.co';

      try {
        $client = \Drupal::httpClient();
        $response = $client->request('GET', $dominio . '/v1/transactions/' . rawurlencode($wompi_transaction_id), [
          'headers' => ['Authorization' => 'Bearer ' . $public_key],
          'timeout' => 8,
        ]);
        $body = json_decode((string) $response->getBody(), TRUE);
        $status = $body['data']['status'] ?? NULL;
        $reference = $body['data']['reference'] ?? NULL;

        if ($status !== NULL && $reference !== NULL) {
          $resultado = ['status' => $status, 'reference' => $reference];
        }
      }
      catch (GuzzleException $e) {
        \Drupal::logger('wompi_donaciones')->error('No se pudo consultar el estado de la transacción @id en la API de Wompi: @msg', [
          '@id' => $wompi_transaction_id,
          '@msg' => $e->getMessage(),
        ]);
      }
    }

    return $resultado;
  }

  /**
   * Guarda el estado real obtenido de la API de Wompi. Se busca por
   * "reference" (que sí existe desde que se creó la transacción
   * PENDIENTE) y de paso se deja guardado el wompi_transaction_id, para
   * que la próxima consulta ya lo encuentre directamente por ese id.
   */
  protected function actualizarEstadoLocal($wompi_transaction_id, $reference, $estado_interno) {
    \Drupal::database()->update('wompi_donaciones_transaction')
      ->fields([
        'status' => $estado_interno,
        'wompi_transaction_id' => $wompi_transaction_id,
        'changed' => \Drupal::time()->getRequestTime(),
      ])
      ->condition('reference', $reference)
      ->execute();
  }

}
