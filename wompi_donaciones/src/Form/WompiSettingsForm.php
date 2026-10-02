<?php

namespace Drupal\wompi_donaciones\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Formulario de configuración de las llaves de Wompi.
 *
 * Las llaves NO se guardan como configuración exportable (no quedan en
 * config/sync ni en el repositorio de código): se guardan con la State
 * API de Drupal, que vive únicamente en la base de datos del sitio.
 * Si prefieren un manejo aún más estricto, estos mismos valores se
 * pueden mover a variables de entorno leídas desde settings.php.
 */
class WompiSettingsForm extends FormBase {

  const STATE_PREFIX = 'wompi_donaciones.';

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'wompi_donaciones_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $state = \Drupal::state();

    $form['environment'] = [
      '#type' => 'select',
      '#title' => $this->t('Ambiente'),
      '#options' => [
        'sandbox' => $this->t('Sandbox (pruebas)'),
        'production' => $this->t('Producción'),
      ],
      '#default_value' => $state->get(self::STATE_PREFIX . 'environment', 'sandbox'),
      '#description' => $this->t('En Sandbox, las peticiones a Wompi usan las llaves de prueba y el dominio de pruebas.'),
    ];

    $form['public_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Llave pública (public key)'),
      '#default_value' => $state->get(self::STATE_PREFIX . 'public_key', ''),
      '#description' => $this->t('Disponible en el Dashboard de Wompi &gt; Desarrolladores. Ejemplo: pub_test_... / pub_prod_...'),
    ];

    $form['private_key'] = [
      '#type' => 'password',
      '#title' => $this->t('Llave privada (private key)'),
      '#description' => $this->t('Se usa únicamente para consultar transacciones directamente en la API de Wompi (no se envía nunca al navegador). @status Deja este campo vacío para mantener el valor ya guardado.', [
        '@status' => $state->get(self::STATE_PREFIX . 'private_key') ? $this->t('Ya hay una llave guardada.') : $this->t('Todavía no se ha configurado.'),
      ]),
    ];

    $form['integrity_secret'] = [
      '#type' => 'password',
      '#title' => $this->t('Secreto de integridad (integrity secret)'),
      '#description' => $this->t('Dashboard de Wompi &gt; Desarrolladores &gt; Secretos para integración técnica. Se usa para calcular la firma "signature:integrity" del Web Checkout. @status Deja este campo vacío para mantener el valor ya guardado.', [
        '@status' => $state->get(self::STATE_PREFIX . 'integrity_secret') ? $this->t('Ya hay un secreto guardado.') : $this->t('Todavía no se ha configurado.'),
      ]),
    ];

    $form['events_secret'] = [
      '#type' => 'password',
      '#title' => $this->t('Secreto de eventos (events secret)'),
      '#description' => $this->t('Dashboard de Wompi &gt; Desarrolladores &gt; Secretos para integración técnica. Se usa para validar la firma de los eventos que llegan al webhook. @status Deja este campo vacío para mantener el valor ya guardado.', [
        '@status' => $state->get(self::STATE_PREFIX . 'events_secret') ? $this->t('Ya hay un secreto guardado.') : $this->t('Todavía no se ha configurado.'),
      ]),
    ];

    $form['redirect_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('URL de redirección tras el pago'),
      '#default_value' => $state->get(self::STATE_PREFIX . 'redirect_url', '/filantropia/compra'),
      '#description' => $this->t('A esta URL vuelve el donante desde Wompi sin importar el resultado del pago (CA-08). Por defecto la página de agradecimiento actual.'),
    ];

    $webhook_url = \Drupal::request()->getSchemeAndHttpHost() . '/wompi/webhook';
    $form['webhook_info'] = [
      '#type' => 'item',
      '#title' => $this->t('URL del webhook para configurar en el Dashboard de Wompi'),
      '#markup' => '<code>' . $webhook_url . '</code>',
    ];

    $form['actions']['#type'] = 'actions';
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Guardar configuración'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $state = \Drupal::state();
    $state->set(self::STATE_PREFIX . 'environment', $form_state->getValue('environment'));
    $state->set(self::STATE_PREFIX . 'public_key', trim($form_state->getValue('public_key')));

    // Los campos de secretos son #type 'password' y se muestran siempre
    // vacíos; si el usuario los deja en blanco, se conserva el valor ya
    // guardado en vez de borrarlo.
    foreach (['private_key', 'integrity_secret', 'events_secret'] as $campo_secreto) {
      $valor = trim($form_state->getValue($campo_secreto));
      if ($valor !== '') {
        $state->set(self::STATE_PREFIX . $campo_secreto, $valor);
      }
    }

    $state->set(self::STATE_PREFIX . 'redirect_url', trim($form_state->getValue('redirect_url')));

    $this->messenger()->addStatus($this->t('Configuración de Wompi guardada.'));
  }

}
