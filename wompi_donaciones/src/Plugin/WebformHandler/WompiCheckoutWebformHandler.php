<?php

namespace Drupal\wompi_donaciones\Plugin\WebformHandler;

use Drupal\Core\Database\IntegrityConstraintViolationException;
use Drupal\webform\Plugin\WebformHandlerBase;
use Drupal\webform\WebformSubmissionInterface;
use Drupal\wompi_donaciones\Exception\WompiReferenceGenerationException;

/**
 * Registra el intento de pago con Wompi para el envío de Causas.
 *
 * Este handler es ADICIONAL al handler "remote_post" que ya existe hacia
 * Zapier (registro en Excel online): no lo reemplaza, no lo modifica y
 * no depende de él. Ambos handlers corren de forma independiente sobre
 * el mismo envío del webform Causas (CA-05b).
 *
 * A diferencia de un primer diseño, este handler NO redirige él mismo al
 * donante a Wompi: la página de confirmación existente del webform
 * ("Detalles de la donación") ya le muestra un resumen de su donación y
 * un botón "Continuar" -antes apuntaba a un formulario oculto de PayU-.
 * Este handler solo deja lista, en postSave(), la referencia única y el
 * registro PENDIENTE en wompi_donaciones_transaction; el botón
 * "Continuar" de esa página de confirmación usa el token
 * [webform_submission:wompi-checkout-url] (ver wompi_donaciones_tokens()
 * en el .module y WompiCheckoutUrlBuilder) para enlazar directamente al
 * Web Checkout de Wompi con la referencia y firma ya calculadas.
 *
 * @WebformHandler(
 *   id = "wompi_checkout",
 *   label = @Translation("Wompi - Redirección a Web Checkout"),
 *   category = @Translation("Externo"),
 *   description = @Translation("Genera la referencia única y guarda el intento de pago para que la página de confirmación pueda enlazar al Web Checkout de Wompi (checkout.wompi.co)."),
 *   cardinality = \Drupal\webform\Plugin\WebformHandlerInterface::CARDINALITY_SINGLE,
 *   results = \Drupal\webform\Plugin\WebformHandlerInterface::RESULTS_PROCESSED,
 *   submission = \Drupal\webform\Plugin\WebformHandlerInterface::SUBMISSION_OPTIONAL,
 * )
 */
class WompiCheckoutWebformHandler extends WebformHandlerBase {

  /**
   * Nombre del elemento del webform "Causas" con el monto de la donación.
   *
   * Definido como constante (en vez de quedar suelto en el código) para
   * que, si el formulario cambia, solo haya que tocar esto.
   */
  const ELEMENT_AMOUNT = 'valor_de_la_donacion_valor_minimo_30_000_';
  const ELEMENT_EMAIL = 'correo_electronico';

  /**
   * {@inheritdoc}
   *
   * Se dispara justo después de que el envío queda guardado, cuando ya
   * sabemos que los 20 campos del formulario pasaron validación. Genera
   * la referencia y deja el registro en PENDIENTE; la página de
   * confirmación (que se muestra justo después) es la que arma el enlace
   * a Wompi a partir de ese registro.
   */
  public function postSave(WebformSubmissionInterface $webform_submission, $update = TRUE) {
    if ($update) {
      // No generamos una referencia nueva si el envío solo se está
      // actualizando (por ejemplo, edición administrativa posterior).
      return;
    }

    $this->crearTransaccionPendiente($webform_submission);
  }

  /**
   * Crea el registro PENDIENTE en wompi_donaciones_transaction.
   *
   * Genera una referencia nueva y única por cada intento de donación
   * (Restricción de la HU: "la referencia... debe ser única por cada
   * intento") y la guarda junto con el monto y el correo del donante.
   *
   * La unicidad la arbitra directamente la restricción UNIQUE de la
   * columna "reference" (insert optimista + reintento ante colisión), en
   * vez de un select previo: así se evita la condición de carrera entre
   * comprobar que la referencia no existe y realmente insertarla.
   */
  protected function crearTransaccionPendiente(WebformSubmissionInterface $webform_submission) {
    $data = $webform_submission->getData();

    $amount_in_cents = $this->montoACentavos($data[self::ELEMENT_AMOUNT] ?? 0);
    $email = $data[self::ELEMENT_EMAIL] ?? '';
    $time = \Drupal::time()->getRequestTime();

    $intentos = 0;
    do {
      $reference = $this->generarReferencia($webform_submission);
      try {
        \Drupal::database()->insert('wompi_donaciones_transaction')
          ->fields([
            'webform_submission_id' => $webform_submission->id(),
            'reference' => $reference,
            'amount_in_cents' => $amount_in_cents,
            'currency' => 'COP',
            'status' => 'PENDIENTE',
            'customer_email' => $email,
            'created' => $time,
            'changed' => $time,
          ])
          ->execute();

        return $reference;
      }
      catch (IntegrityConstraintViolationException $e) {
        // Colisión de referencia extremadamente improbable: se reintenta
        // con un sufijo aleatorio nuevo.
        $intentos++;
      }
    } while ($intentos < 5);

    throw new WompiReferenceGenerationException('No se pudo generar una referencia única de Wompi tras varios intentos.');
  }

  /**
   * Genera una referencia corta y trazable hasta el envío.
   */
  protected function generarReferencia(WebformSubmissionInterface $webform_submission) {
    $sufijo = strtoupper(substr(bin2hex(random_bytes(5)), 0, 8));
    return 'EAN-DON-' . $webform_submission->id() . '-' . $sufijo;
  }

  /**
   * Convierte el monto capturado en el webform a centavos sin usar
   * aritmética de punto flotante (evita errores de redondeo binario en
   * valores con decimales).
   */
  protected function montoACentavos($valor) {
    $normalizado = str_replace(',', '.', trim((string) $valor));
    if (!is_numeric($normalizado)) {
      return 0;
    }

    $partes = explode('.', $normalizado, 2);
    $entero = (int) $partes[0];
    $decimales = isset($partes[1]) ? str_pad(substr($partes[1], 0, 2), 2, '0') : '00';

    return ($entero * 100) + (int) $decimales;
  }

}
