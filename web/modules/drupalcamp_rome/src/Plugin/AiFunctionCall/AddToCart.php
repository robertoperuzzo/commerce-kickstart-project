<?php

namespace Drupal\drupalcamp_rome\Plugin\AiFunctionCall;

use Drupal\ai\Attribute\FunctionCall;
use Drupal\ai\Base\FunctionCallBase;
use Drupal\ai\PluginManager\AiDataTypeConverterPluginManager;
use Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface;
use Drupal\ai\Utility\ContextDefinitionNormalizer;
use Drupal\commerce_cart\CartManagerInterface;
use Drupal\commerce_cart\CartProviderInterface;
use Drupal\commerce_store\CurrentStoreInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Plugin to add a product to the shopping cart.
 */
#[FunctionCall(
  id: 'drupalcamp_rome:add_to_cart',
  function_name: 'add_to_cart',
  name: 'Add Product to Cart',
  description: 'Adds a product to the shopping cart by product variation ID.',
  group: 'drupalcamp_rome',
  context_definitions: [
    'product_variation_id' => new ContextDefinition(
      data_type: 'integer',
      label: new TranslatableMarkup("Product Variation ID"),
      required: TRUE,
      description: new TranslatableMarkup("The product variation ID to add to cart.")
    ),
    'quantity' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup("Quantity"),
      required: FALSE,
      description: new TranslatableMarkup("The quantity of the product to add."),
      default_value: '1'
    ),
    'combine' => new ContextDefinition(
      data_type: 'boolean',
      label: new TranslatableMarkup("Combine"),
      required: FALSE,
      description: new TranslatableMarkup("Whether to combine with existing cart items if matching."),
      default_value: TRUE
    ),
  ]
)]
class AddToCart extends FunctionCallBase implements ExecutableFunctionCallInterface {

  /**
   * {@inheritdoc}
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    ContextDefinitionNormalizer $context_definition_normalizer,
    AiDataTypeConverterPluginManager $data_type_converter_manager,
    protected ?EntityTypeManagerInterface $entityTypeManager = NULL,
    protected ?CartManagerInterface $cartManager = NULL,
    protected ?CartProviderInterface $cartProvider = NULL,
    protected ?AccountInterface $currentUser = NULL,
    protected ?CurrentStoreInterface $currentStore = NULL,
  ) {
    parent::__construct(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $context_definition_normalizer,
      $data_type_converter_manager
    );
  }

  /**
   * {@inheritdoc}
   */
  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition
  ): static {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->entityTypeManager = $container->get('entity_type.manager');
    $instance->cartManager = $container->get('commerce_cart.cart_manager');
    $instance->cartProvider = $container->get('commerce_cart.cart_provider');
    $instance->currentUser = $container->get('current_user');
    $instance->currentStore = $container->get('commerce_store.current_store');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function execute(): void {
    // Retrieve context values.
    $product_variation_id = $this->getContextValue('product_variation_id');
    $quantity = $this->getContextValue('quantity');
    $combine = $this->getContextValue('combine');

    // Load product variation.
    $variation_storage = $this->entityTypeManager->getStorage('commerce_product_variation');
    $variation = $variation_storage->load($product_variation_id);

    if (!$variation) {
      $this->stringOutput = "Error: Product variation with ID {$product_variation_id} not found.";
      return;
    }

    // Validate variation is purchasable.
    if (!$variation->isPublished()) {
      $this->stringOutput = "Error: Product variation is not available for purchase.";
      return;
    }

    // Get current store.
    $store = $this->currentStore->getStore();
    if (!$store) {
      $this->stringOutput = "Error: No store context available.";
      return;
    }

    // Get or create cart.
    $cart = $this->cartProvider->getCart('default', $store);
    if (!$cart) {
      $cart = $this->cartProvider->createCart('default', $store);
    }

    // Add to cart.
    try {
      $order_item = $this->cartManager->addEntity(
        $cart,
        $variation,
        $quantity,
        $combine
      );

      $this->stringOutput = sprintf(
        'Successfully added %s x "%s" to cart. Order item ID: %s',
        $quantity,
        $variation->getTitle(),
        $order_item->id()
      );
    }
    catch (\Exception $e) {
      $this->stringOutput = "Error adding to cart: " . $e->getMessage();
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getReadableOutput(): string {
    return $this->stringOutput;
  }

}
