<?php

namespace Drupal\wompi_donaciones\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Query\Merge;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Recibe los eventos (webhook) que envía Wompi para confirmar el pago.
 *
 * Esto es lo que decide, del lado del servidor, si una donación queda
 * APROBADA (CA-06 y CA-07): nunca se marca una donación como aprobada
 * solo porque el navegador volvió a /filantropia/compra.
 */
class WompiWebhookController extends ControllerBase {

  const STATE_PREFIX = 'wompi_donaciones.';

  /**
   * Estados que llegan desde Wompi y cómo los guardamos nosotros.
   */
  const MAPA_ESTADOS = [
    'APPROVED' => 'APROBADA',
    'DECLINED' => 'DECLINADA',
    'VOIDED' => 'DECLINADA',
    'ERROR' => 'ERROR',
    'PENDING' => 'PENDIENTE',
  ];

  /**
   * Punto de entrada de /wompi/webhook.
   */
  public function handle(Request $request) {
    $raw_body = $request->getContent();
    $payload = json_decode($raw_body, TRUE);

    return $this->procesarPayload($payload, $raw_body);
  }

  /**
   * Valida el payload y, si todo está en orden, procesa el evento.
   *
   * Centralizado en un único punto de salida (en vez de varios "return"
   * repartidos en handle()) para mantener la regla de complejidad de
   * SonarQube (máximo de returns por método).
   */
  protected function procesarPayload($payload, $raw_body) {
    if (!is_array($payload) || empty($payload['signature']['checksum'])) {
      \Drupal::logger('wompi_donaciones')->warning('Webhook de Wompi recibido con formato inválido.');
      $response = new JsonResponse(['error' => 'invalid_payload'], 400);
    }
    elseif (!$this->firmaValida($payload)) {
      \Drupal::logger('wompi_donaciones')->error('Webhook de Wompi rechazado: firma inválida. Evento: @event', [
        '@event' => $payload['event'] ?? 'desconocido',
      ]);
      $response = new JsonResponse(['error' => 'invalid_signature'], 401);
    }
    elseif (($payload['event'] ?? '') !== 'transaction.updated') {
      // Reconocemos el evento pero no hay nada que procesar.
      $response = new JsonResponse(['status' => 'ignored'], 200);
    }
    else {
      $transaction = $payload['data']['transaction'] ?? NULL;
      if (!$transaction || empty($transaction['reference'])) {
        $response = new JsonResponse(['error' => 'missing_transaction'], 400);
      }
      else {
        $this->actualizarTransaccion($transaction, $raw_body);
        // Wompi espera un 200 para no reintentar el evento.
        $response = new JsonResponse(['status' => 'ok'], 200);
      }
    }

    return $response;
  }

  /**
   * Valida el checksum del evento contra el secreto de eventos.
   *
   * Fórmula oficial de Wompi: se concatenan, EN EL ORDEN que indica
   * signature.properties, los valores de esas propiedades dentro de
   * "data" (por ejemplo "transaction.id" => $data['transaction']['id']),
   * luego el timestamp del evento, y luego el secreto de eventos.
   * Esa cadena completa se pasa por SHA256 y el resultado se compara
   * contra signature.checksum.
   */
  protected function firmaValida(array $payload) {
    $events_secret = \Drupal::state()->get(self::STATE_PREFIX . 'events_secret');
    if (empty($events_secret)) {
      \Drupal::logger('wompi_donaciones')->error('Llegó un webhook de Wompi pero no hay events_secret configurado en /admin/config/services/wompi-donaciones.');
      return FALSE;
    }

    $properties = $payload['signature']['properties'] ?? [];
    $timestamp = $payload['timestamp'] ?? '';
    $checksum_recibido = $payload['signature']['checksum'] ?? '';

    if (empty($properties) || $timestamp === '' || empty($checksum_recibido)) {
      return FALSE;
    }

    $cadena = '';
    foreach ($properties as $property_path) {
      $cadena .= $this->valorPorRuta($payload['data'] ?? [], $property_path);
    }
    $cadena .= $timestamp . $events_secret;

    $checksum_calculado = strtoupper(hash('sha256', $cadena));

    return hash_equals($checksum_calculado, strtoupper($checksum_recibido));
  }

  /**
   * Resuelve algo como "transaction.status" dentro de un array anidado.
   */
  protected function valorPorRuta(array $data, $ruta) {
    $partes = explode('.', $ruta);
    $valor = $data;
    foreach ($partes as $parte) {
      if (!is_array($valor) || !array_key_exists($parte, $valor)) {
        return '';
      }
      $valor = $valor[$parte];
    }
    return is_scalar($valor) ? (string) $valor : '';
  }

  /**
   * Actualiza (o crea, si por alguna razón no existía) el registro de la
   * transacción con el estado real que confirma Wompi.
   *
   * Usa merge() (upsert atómico de Drupal) en vez de select+insert/update
   * por separado: así dos webhooks concurrentes para la misma referencia
   * nunca chocan contra la restricción UNIQUE de la columna "reference".
   */
  protected function actualizarTransaccion(array $transaction, $raw_body) {
    $reference = $transaction['reference'];
    $estado_wompi = $transaction['status'] ?? '';

    if (!isset(self::MAPA_ESTADOS[$estado_wompi])) {
      \Drupal::logger('wompi_donaciones')->warning('Webhook de Wompi con un estado desconocido (@status) para la referencia @ref. Se guarda como ERROR.', [
        '@status' => $estado_wompi,
        '@ref' => $reference,
      ]);
    }
    $estado_interno = self::MAPA_ESTADOS[$estado_wompi] ?? 'ERROR';
    $time = \Drupal::time()->getRequestTime();

    $resultado = \Drupal::database()->merge('wompi_donaciones_transaction')
      ->key(['reference' => $reference])
      ->insertFields([
        'webform_submission_id' => 0,
        'amount_in_cents' => $transaction['amount_in_cents'] ?? 0,
        'currency' => $transaction['currency'] ?? 'COP',
        'customer_email' => $transaction['customer_email'] ?? NULL,
        'created' => $time,
      ])
      ->fields([
        'status' => $estado_interno,
        'wompi_transaction_id' => $transaction['id'] ?? NULL,
        'payment_method_type' => $transaction['payment_method_type'] ?? NULL,
        'raw_event' => $raw_body,
        'changed' => $time,
      ])
      ->execute();

    if ($resultado === Merge::STATUS_INSERT) {
      // No debería pasar en operación normal (la referencia siempre se
      // crea antes de redirigir a Wompi), pero dejamos el evento
      // guardado igual para no perder información.
      \Drupal::logger('wompi_donaciones')->warning('Webhook de Wompi para una referencia no registrada localmente: @ref', ['@ref' => $reference]);
    }
  }

}
