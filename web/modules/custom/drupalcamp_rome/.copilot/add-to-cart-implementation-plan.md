# Implementation Plan: AddToCart FunctionCall Plugin

**Date:** 2025-11-04  
**Module:** drupalcamp_rome  
**Purpose:** Create an AI FunctionCall plugin to add products to the cart

---

## Overview

Create an AI FunctionCall plugin that integrates Drupal Commerce cart functionality with the AI module, allowing AI agents to add products to a user's shopping cart.

---

## File Structure

```
web/modules/drupalcamp_rome/
├── drupalcamp_rome.info.yml (already exists)
└── src/
    └── Plugin/
        └── AiFunctionCall/
            └── AddToCart.php (NEW)
```

---

## Implementation Details

### 1. Plugin Class: `AddToCart.php`

**Location:** `web/modules/drupalcamp_rome/src/Plugin/AiFunctionCall/AddToCart.php`

**Namespace:** `Drupal\drupalcamp_rome\Plugin\AiFunctionCall`

**Extends:** `FunctionCallBase` (from `Drupal\ai\Base\FunctionCallBase`)

**Implements:** `ExecutableFunctionCallInterface` (from `Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface`)

#### Attribute Configuration

```php
#[FunctionCall(
  id: 'drupalcamp_rome:add_to_cart',
  function_name: 'add_to_cart',
  name: 'Add Product to Cart',
  description: 'Adds a product to the shopping cart by product variation SKU or ID',
  group: 'drupalcamp_rome',
  context_definitions: [
    // See context definitions below
  ]
)]
```

- **Plugin ID:** `drupalcamp_rome:add_to_cart`
- **Function Name:** `add_to_cart`
- **Name:** "Add Product to Cart"
- **Description:** "Adds a product to the shopping cart by product variation SKU or ID"
- **Group:** `drupalcamp_rome`

#### Context Definitions (Parameters)

1. **product_variation_id** (integer, required)
   ```php
   'product_variation_id' => new ContextDefinition(
     data_type: 'integer',
     label: new TranslatableMarkup("Product Variation ID"),
     required: TRUE,
     description: new TranslatableMarkup("The product variation ID to add to cart.")
   ),
   ```
   - **Type:** integer
   - **Required:** Yes
   - **Description:** "The product variation ID to add to cart"
   - **Alternative:** Could use SKU instead (see Option A below)

2. **quantity** (string, optional, default: "1")
   ```php
   'quantity' => new ContextDefinition(
     data_type: 'string',
     label: new TranslatableMarkup("Quantity"),
     required: FALSE,
     description: new TranslatableMarkup("The quantity of the product to add."),
     default_value: '1'
   ),
   ```
   - **Type:** string (Commerce uses string for quantities to support decimals)
   - **Required:** No
   - **Default:** "1"
   - **Description:** "The quantity of the product to add"
   - **Constraint:** Must be positive number

3. **combine** (boolean, optional, default: TRUE)
   ```php
   'combine' => new ContextDefinition(
     data_type: 'boolean',
     label: new TranslatableMarkup("Combine"),
     required: FALSE,
     description: new TranslatableMarkup("Whether to combine with existing cart items if matching."),
     default_value: TRUE
   ),
   ```
   - **Type:** boolean
   - **Required:** No
   - **Default:** TRUE
   - **Description:** "Whether to combine with existing cart items if matching"

---

### 2. Required Services (Dependency Injection)

All services must be injected via the `create()` method and constructor:

1. **entity_type.manager** (`EntityTypeManagerInterface`)
   - **Purpose:** To load product variations
   - **Access:** `$container->get('entity_type.manager')`
   - **Use:** `$this->entityTypeManager->getStorage('commerce_product_variation')->load($id)`

2. **commerce_cart.cart_manager** (`CartManagerInterface`)
   - **Purpose:** To add items to cart
   - **Access:** `$container->get('commerce_cart.cart_manager')`
   - **Use:** `$this->cartManager->addEntity($cart, $variation, $quantity, $combine)`

3. **commerce_cart.cart_provider** (`CartProviderInterface`)
   - **Purpose:** To get or create cart for current user
   - **Access:** `$container->get('commerce_cart.cart_provider')`
   - **Use:** `$this->cartProvider->getCart('default', $store)` or `createCart()`

4. **current_user** (`AccountInterface`)
   - **Purpose:** To identify the user
   - **Access:** `$container->get('current_user')`
   - **Use:** Passed to cart provider if needed

5. **commerce_store.current_store** (`CurrentStoreInterface`)
   - **Purpose:** To get current store context
   - **Access:** `$container->get('commerce_store.current_store')`
   - **Use:** `$this->currentStore->getStore()`

---

### 3. Implementation Methods

#### `create()` Method

```php
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
```

- Call `parent::create()` to get base instance
- Inject all required services
- Return configured instance

#### `__construct()` Method

```php
public function __construct(
  array $configuration,
  $plugin_id,
  $plugin_definition,
  ContextDefinitionNormalizer $context_definition_normalizer,
  AiDataTypeConverterPluginManager $data_type_converter_manager,
) {
  parent::__construct(
    $configuration,
    $plugin_id,
    $plugin_definition,
    $context_definition_normalizer,
    $data_type_converter_manager
  );
}
```

- Accept all services as protected properties (defined in class)
- Call parent constructor with required base dependencies

#### `execute()` Method Logic

**Step-by-step implementation:**

1. **Retrieve context values:**
   ```php
   $product_variation_id = $this->getContextValue('product_variation_id');
   $quantity = $this->getContextValue('quantity');
   $combine = $this->getContextValue('combine');
   ```

2. **Load product variation:**
   ```php
   $variation_storage = $this->entityTypeManager->getStorage('commerce_product_variation');
   $variation = $variation_storage->load($product_variation_id);
   
   if (!$variation) {
     $this->stringOutput = "Error: Product variation with ID {$product_variation_id} not found.";
     return;
   }
   ```

3. **Validate variation is purchasable:**
   ```php
   if (!$variation->isPublished()) {
     $this->stringOutput = "Error: Product variation is not available for purchase.";
     return;
   }
   ```

4. **Get current store:**
   ```php
   $store = $this->currentStore->getStore();
   if (!$store) {
     $this->stringOutput = "Error: No store context available.";
     return;
   }
   ```

5. **Get or create cart:**
   ```php
   $cart = $this->cartProvider->getCart('default', $store);
   if (!$cart) {
     $cart = $this->cartProvider->createCart('default', $store);
   }
   ```

6. **Add to cart:**
   ```php
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
   } catch (\Exception $e) {
     $this->stringOutput = "Error adding to cart: " . $e->getMessage();
   }
   ```

#### `getReadableOutput()` Method

```php
public function getReadableOutput(): string {
  return $this->stringOutput;
}
```

- Return the human-readable confirmation or error message
- This is what the AI agent will receive as feedback

#### Optional: `getStructuredOutput()` Method

```php
public function getStructuredOutput(): array {
  return [
    'success' => !str_starts_with($this->stringOutput, 'Error'),
    'message' => $this->stringOutput,
    'order_item_id' => $this->orderItemId ?? NULL,
    'product_title' => $this->productTitle ?? NULL,
    'quantity' => $this->quantity ?? NULL,
    'cart_id' => $this->cartId ?? NULL,
  ];
}
```

- Provides structured data for programmatic access
- Store values as class properties during execution

---

### 4. Error Handling

Handle the following scenarios with appropriate error messages:

| Scenario | Error Message | Handling |
|----------|--------------|----------|
| Product variation not found | `"Error: Product variation with ID {id} not found."` | Check if `load()` returns NULL |
| Product variation not purchasable | `"Error: Product variation is not available for purchase."` | Check `isPublished()` |
| Invalid quantity | `"Error: Quantity must be a positive number."` | Validate before adding |
| Store not available | `"Error: No store context available."` | Check if store exists |
| Cart creation failure | `"Error: Unable to create cart."` | Catch exceptions |
| Permission issues | `"Error: User does not have permission to add to cart."` | Check permissions if needed |
| General exception | `"Error adding to cart: {exception message}"` | Wrap in try-catch |

**Error Handling Pattern:**
```php
if (!$variation) {
  $this->stringOutput = 'Error: Product variation not found';
  return;
}
```

---

### 5. Alternative Implementation Options

#### Option A: Use SKU Instead of ID

**Pros:**
- More user-friendly for AI
- SKU is a business identifier
- Easier for non-technical users

**Changes:**
```php
'sku' => new ContextDefinition(
  data_type: 'string',
  label: new TranslatableMarkup("Product SKU"),
  required: TRUE,
  description: new TranslatableMarkup("The product variation SKU to add to cart.")
),
```

**Loading by SKU:**
```php
$variations = $variation_storage->loadByProperties(['sku' => $sku]);
$variation = reset($variations);
```

#### Option B: Use Product ID + Attribute Values

**Pros:**
- More flexible for products with variations
- AI can select specific attributes

**Cons:**
- More complex implementation
- Requires attribute resolution logic

#### Option C: Support Both SKU and ID

**Pros:**
- Maximum flexibility
- Supports different use cases

**Implementation:**
- Make both `product_variation_id` and `sku` optional
- Require at least one to be provided
- Check which one is provided and load accordingly

```php
$product_variation_id = $this->getContextValue('product_variation_id');
$sku = $this->getContextValue('sku');

if ($product_variation_id) {
  $variation = $variation_storage->load($product_variation_id);
} elseif ($sku) {
  $variations = $variation_storage->loadByProperties(['sku' => $sku]);
  $variation = reset($variations);
} else {
  $this->stringOutput = 'Error: Either product_variation_id or sku must be provided.';
  return;
}
```

**Recommended:** Start with Option A (SKU) as it's more intuitive for AI agents.

---

### 6. Module Dependencies

Update `drupalcamp_rome.info.yml` to include all required dependencies:

```yaml
name: 'DrupalCamp Rome'
type: module
description: 'Contains the custom features for the DrupalCamp Rome demo.'
package: Custom
core_version_requirement: ^10 || ^11
dependencies:
  - ai:ai
  - ai_agents:ai_agents
  - ai_provider_openai:ai_provider_openai
  - commerce_cart:commerce_cart
  - commerce_product:commerce_product
  - commerce_store:commerce_store
  - eca:eca
  - search_api_typesense:search_api_typesense
```

---

### 7. Complete Class Structure Template

```php
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
   * The entity type manager.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The cart manager.
   */
  protected CartManagerInterface $cartManager;

  /**
   * The cart provider.
   */
  protected CartProviderInterface $cartProvider;

  /**
   * The current user.
   */
  protected AccountInterface $currentUser;

  /**
   * The current store.
   */
  protected CurrentStoreInterface $currentStore;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    ContextDefinitionNormalizer $context_definition_normalizer,
    AiDataTypeConverterPluginManager $data_type_converter_manager,
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
    // Implementation here
  }

  /**
   * {@inheritdoc}
   */
  public function getReadableOutput(): string {
    return $this->stringOutput;
  }

}
```

---

### 8. Testing Considerations

Once implemented, test with the following scenarios:

#### Valid Cases
- ✅ Valid product variation ID with default quantity (1)
- ✅ Valid product variation ID with custom quantity (e.g., "2", "2.5")
- ✅ Adding same product twice (combine = TRUE)
- ✅ Adding same product twice (combine = FALSE)
- ✅ Adding to empty cart
- ✅ Adding to existing cart with other items
- ✅ Anonymous user cart
- ✅ Authenticated user cart

#### Error Cases
- ❌ Invalid/non-existent product variation ID
- ❌ Negative quantity
- ❌ Zero quantity
- ❌ Invalid quantity format
- ❌ Unpublished product variation
- ❌ No store context
- ❌ Permission denied scenarios

#### Test Methods
1. **Manual testing** via AI agent interface
2. **Drush commands** to invoke the function
3. **Unit tests** (optional but recommended)
4. **Kernel tests** for integration testing

---

### 9. Usage Example

Once implemented, AI agents can invoke this tool:

**AI Agent Request:**
```
User: "Add 2 units of product variation 123 to my cart"

AI Agent uses function:
  Function: add_to_cart
  Arguments:
    - product_variation_id: 123
    - quantity: "2"
    - combine: true

Response:
  "Successfully added 2 x 'Product Title' to cart. Order item ID: 456"
```

**JSON Function Call:**
```json
{
  "function": "add_to_cart",
  "arguments": {
    "product_variation_id": 123,
    "quantity": "2",
    "combine": true
  }
}
```

---

### 10. Implementation Steps

Follow these steps in order:

1. **Create directory structure:**
   ```bash
   mkdir -p web/modules/drupalcamp_rome/src/Plugin/AiFunctionCall
   ```

2. **Create the AddToCart.php file:**
   - Use the template above
   - Implement all required methods
   - Add proper error handling

3. **Update module dependencies:**
   - Ensure `drupalcamp_rome.info.yml` includes all Commerce and AI dependencies

4. **Clear Drupal cache:**
   ```bash
   drush cr
   ```

5. **Verify plugin discovery:**
   ```bash
   drush php-eval "print_r(array_keys(\Drupal::service('plugin.manager.ai_function_call')->getDefinitions()));"
   ```
   - Look for `drupalcamp_rome:add_to_cart` in the output

6. **Test with AI agent:**
   - Configure an AI agent to use this function
   - Test with various scenarios from the testing checklist

7. **Monitor and debug:**
   - Check logs: `drush watchdog:show`
   - Debug output with `\Drupal::logger('drupalcamp_rome')->notice()`

8. **Iterate and refine:**
   - Improve error messages based on real usage
   - Add additional validations if needed
   - Consider implementing alternative options (SKU support, etc.)

---

### 11. Future Enhancements

Consider these improvements for future iterations:

- **SKU Support:** Add ability to add products by SKU instead of ID
- **Product Search:** Integrate with search to find products by name
- **Bulk Add:** Support adding multiple products in one call
- **Validation:** Add stock availability checks
- **Customization:** Support adding custom fields to order items
- **Cart Management:** Additional functions for viewing, updating, removing items
- **Promotions:** Apply promotion codes during add to cart
- **Wishlist:** Alternative function to add to wishlist instead of cart

---

### 12. Resources and References

**Drupal Commerce Documentation:**
- [Cart Module](https://docs.drupalcommerce.org/commerce2/developer-guide/core/cart)
- [Order Items](https://docs.drupalcommerce.org/commerce2/developer-guide/core/orders)
- [Product Variations](https://docs.drupalcommerce.org/commerce2/developer-guide/core/products)

**AI Module Documentation:**
- [FunctionCall Plugins](https://www.drupal.org/project/ai)
- Plugin examples in `web/modules/contrib/ai/tests/modules/ai_test/src/Plugin/AiFunctionCall/`

**Example Implementations in Codebase:**
- `web/modules/contrib/ai/tests/modules/ai_test/src/Plugin/AiFunctionCall/Weather.php`
- `web/modules/contrib/ai/tests/modules/ai_test/src/Plugin/AiFunctionCall/Calculator.php`
- `web/modules/contrib/eca/modules/base/src/Plugin/AiFunctionCall/Eca.php`

**Drupal Services:**
- Entity Type Manager: [API Documentation](https://api.drupal.org/api/drupal/core!lib!Drupal!Core!Entity!EntityTypeManagerInterface.php/interface/EntityTypeManagerInterface)
- Cart Manager: `web/modules/contrib/commerce/modules/cart/src/CartManagerInterface.php`
- Cart Provider: `web/modules/contrib/commerce/modules/cart/src/CartProviderInterface.php`

---

## Summary

This plan provides a comprehensive blueprint for implementing an AI FunctionCall plugin that integrates Drupal Commerce cart functionality with AI agents. The implementation follows Drupal and Commerce best practices while maintaining clean, maintainable code that can be easily extended in the future.

**Key Points:**
- ✅ Extends standard Drupal AI FunctionCall plugin base
- ✅ Integrates with Commerce Cart API properly
- ✅ Includes comprehensive error handling
- ✅ Provides clear, actionable output for AI agents
- ✅ Supports future enhancements and alternatives
- ✅ Well-documented and testable

**Estimated Implementation Time:** 2-4 hours (including testing)

**Dependencies Status:** ✅ All required modules are already in composer.json
