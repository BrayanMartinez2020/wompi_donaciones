<?php

namespace Drupal\wompi_donaciones\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\wompi_donaciones\WompiTransactionStatusResolver;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Expone el estado real de una donación (aprobada/cancelada/pendiente)
 * para que la pantalla "¡Gracias por tu aporte!" pueda mostrar el
 * mensaje correcto (CA06-CA09 de HU001.1), consultado desde el propio
 * navegador del donante justo cuando vuelve de Wompi.
 *
 * Solo devuelve el resultado ya traducido a lo que necesita ver el
 * donante (estado + mensaje); nunca expone llaves, ids internos de
 * envío del webform, ni el resto del detalle de la transacción.
 */
class WompiEstadoController extends ControllerBase {

  /**
   * @var \Drupal\wompi_donaciones\WompiTransactionStatusResolver
   */
  protected $transactionStatusResolver;

  public function __construct(WompiTransactionStatusResolver $transaction_status_resolver) {
    $this->transactionStatusResolver = $transaction_status_resolver;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static($container->get('wompi_donaciones.transaction_status_resolver'));
  }

  /**
   * Punto de entrada de /wompi/estado/{wompi_transaction_id}.
   */
  public function handle($wompi_transaction_id) {
    $resultado = $this->transactionStatusResolver->resolver($wompi_transaction_id);

    $response = new JsonResponse($resultado);
    $response->headers->set('Cache-Control', 'no-store');
    return $response;
  }

}
