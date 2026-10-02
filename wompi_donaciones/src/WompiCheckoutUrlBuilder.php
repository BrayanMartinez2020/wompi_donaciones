<?php

namespace Drupal\wompi_donaciones;

use Drupal\Core\Url;
use Drupal\webform\WebformSubmissionInterface;

/**
 * Construye la URL del Web Checkout de Wompi para un envío ya registrado.
 *
 * La usa el token [webform_submission:wompi-checkout-url] (ver
 * wompi_donaciones_tokens() en el .module) para que la página de
 * confirmación existente del webform Causas ("Detalles de la donación")
 * pueda enlazar directamente a Wompi en su botón "Continuar", en vez de
 * al formulario oculto de PayU que tenía antes.
 *
 * No calcula nada nuevo: lee el registro PENDIENTE que
 * WompiCheckoutWebformHandler::postSave() ya dejó guardado en
 * wompi_donaciones_transaction para este envío, y con eso arma la URL.
 */
class WompiCheckoutUrlBuilder {

  const STATE_PREFIX = 'wompi_donaciones.';

  /**
   * Mensaje de error genérico para el donante (nunca detalles técnicos).
   */
  const MENSAJE_ERROR_DONANTE = 'En este momento no es posible procesar el pago. Por favor intenta más tarde o escríbenos a filantropia@universidadean.edu.co.';

  /**
   * @return array
   *   ['url' => string|null, 'error' => string|null]
   */
  public function build(WebformSubmissionInterface $webform_submission) {
    $transaction = \Drupal::database()->select('wompi_donaciones_transaction', 't')
      ->fields('t', ['reference', 'amount_in_cents', 'currency', 'customer_email'])
      ->condition('webform_submission_id', $webform_submission->id())
      ->orderBy('id', 'DESC')
      ->range(0, 1)
      ->execute()
      ->fetchAssoc();

    if (!$transaction) {
      // No debería pasar (postSave siempre corre antes de que se muestre
      // esta página), pero si pasa, no dejamos al donante sin explicación.
      \Drupal::logger('wompi_donaciones')->error('No se encontró un intento de pago Wompi para el envío #@id al construir la URL de checkout.', ['@id' => $webform_submission->id()]);
      return ['url' => NULL, 'error' => self::MENSAJE_ERROR_DONANTE];
    }

    $state = \Drupal::state();
    $public_key = $state->get(self::STATE_PREFIX . 'public_key');
    $integrity_secret = $state->get(self::STATE_PREFIX . 'integrity_secret');

    if (empty($public_key) || empty($integrity_secret)) {
      // Las llaves de Wompi todavía no están configuradas. Lo dejamos
      // registrado y avisamos al donante en vez de mandarlo a un
      // checkout mal configurado.
      \Drupal::logger('wompi_donaciones')->error('Intento de pago @ref sin llaves de Wompi configuradas en /admin/config/services/wompi-donaciones.', ['@ref' => $transaction['reference']]);
      return ['url' => NULL, 'error' => self::MENSAJE_ERROR_DONANTE];
    }

    $reference = $transaction['reference'];
    $amount_in_cents = $transaction['amount_in_cents'];
    $currency = $transaction['currency'];

    // Fórmula oficial de Wompi (docs.wompi.co):
    // signature:integrity = SHA256(referencia + monto_en_centavos + moneda + secreto_de_integridad)
    $signature = hash('sha256', $reference . $amount_in_cents . $currency . $integrity_secret);

    $redirect_path = $state->get(self::STATE_PREFIX . 'redirect_url', '/filantropia/compra');
    $redirect_url = \Drupal::request()->getSchemeAndHttpHost() . $redirect_path;

    $query = [
      'public-key' => $public_key,
      'currency' => $currency,
      'amount-in-cents' => $amount_in_cents,
      'reference' => $reference,
      'signature:integrity' => $signature,
      'redirect-url' => $redirect_url,
    ];

    if (!empty($transaction['customer_email'])) {
      $query['customer-data:email'] = $transaction['customer_email'];
    }

    $url = Url::fromUri('https://checkout.wompi.co/p/', ['query' => $query])->toString();

    return ['url' => $url, 'error' => NULL];
  }

}
