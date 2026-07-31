<?php
/**
 * Module PrestaShop — envoie chaque création / MAJ / désactivation produit
 * vers l'endpoint catalogue de Trouvez Votre Parfum (temps réel).
 *
 * Installation :
 *   1. Copier ce dossier dans /modules/tzpcatalogsync de PrestaShop
 *   2. Modules → TZP Catalogue Sync → Installer
 *   3. Configurer URL webhook + clé API
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class TzpCatalogSync extends Module
{
    public function __construct()
    {
        $this->name = 'tzpcatalogsync';
        $this->tab = 'administration';
        $this->version = '1.0.0';
        $this->author = 'ZeParfums';
        $this->need_instance = 0;
        $this->bootstrap = true;
        $this->ps_versions_compliancy = ['min' => '1.7.0.0', 'max' => _PS_VERSION_];

        parent::__construct();

        $this->displayName = $this->l('TZP Catalogue Sync');
        $this->description = $this->l('Synchronise les produits PrestaShop vers Trouvez Votre Parfum en temps réel.');
        $this->confirmUninstall = $this->l('Désinstaller le module de synchronisation catalogue ?');
    }

    public function install()
    {
        return parent::install()
            && $this->registerHook('actionProductSave')
            && $this->registerHook('actionProductDelete')
            && $this->registerHook('actionUpdateQuantity')
            && Configuration::updateValue('TZP_SYNC_URL', '')
            && Configuration::updateValue('TZP_SYNC_API_KEY', '')
            && Configuration::updateValue('TZP_SYNC_ENABLED', 1)
            && Configuration::updateValue('TZP_SYNC_DEACTIVATE_OOS', 0);
    }

    public function uninstall()
    {
        Configuration::deleteByName('TZP_SYNC_URL');
        Configuration::deleteByName('TZP_SYNC_API_KEY');
        Configuration::deleteByName('TZP_SYNC_ENABLED');
        Configuration::deleteByName('TZP_SYNC_DEACTIVATE_OOS');
        return parent::uninstall();
    }

    public function getContent()
    {
        $output = '';

        if (Tools::isSubmit('submitTzpCatalogSync')) {
            Configuration::updateValue('TZP_SYNC_URL', trim(Tools::getValue('TZP_SYNC_URL')));
            Configuration::updateValue('TZP_SYNC_API_KEY', trim(Tools::getValue('TZP_SYNC_API_KEY')));
            Configuration::updateValue('TZP_SYNC_ENABLED', (int)Tools::getValue('TZP_SYNC_ENABLED'));
            Configuration::updateValue('TZP_SYNC_DEACTIVATE_OOS', (int)Tools::getValue('TZP_SYNC_DEACTIVATE_OOS'));
            $output .= $this->displayConfirmation($this->l('Paramètres enregistrés.'));

            if (Tools::getValue('TZP_SYNC_TEST')) {
                $result = $this->sendRequest(['action' => 'ping']);
                if (!empty($result['ok'])) {
                    $output .= $this->displayConfirmation($this->l('Test OK : webhook joignable.'));
                } else {
                    $err = isset($result['error']) ? $result['error'] : $this->l('Échec du test.');
                    $output .= $this->displayError($err);
                }
            }
        }

        return $output . $this->renderForm();
    }

    protected function renderForm()
    {
        $fields = [
            'form' => [
                'legend' => [
                    'title' => $this->l('Synchronisation Trouvez Votre Parfum'),
                    'icon' => 'icon-cogs',
                ],
                'input' => [
                    [
                        'type' => 'switch',
                        'label' => $this->l('Activer la sync'),
                        'name' => 'TZP_SYNC_ENABLED',
                        'is_bool' => true,
                        'values' => [
                            ['id' => 'on', 'value' => 1, 'label' => $this->l('Oui')],
                            ['id' => 'off', 'value' => 0, 'label' => $this->l('Non')],
                        ],
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('URL webhook'),
                        'name' => 'TZP_SYNC_URL',
                        'desc' => $this->l('Ex. https://zeparfumsideal.com/api/catalog-sync.php'),
                        'required' => true,
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('Clé API (Bearer)'),
                        'name' => 'TZP_SYNC_API_KEY',
                        'required' => true,
                    ],
                    [
                        'type' => 'switch',
                        'label' => $this->l('Désactiver si rupture de stock'),
                        'name' => 'TZP_SYNC_DEACTIVATE_OOS',
                        'is_bool' => true,
                        'values' => [
                            ['id' => 'oos_on', 'value' => 1, 'label' => $this->l('Oui')],
                            ['id' => 'oos_off', 'value' => 0, 'label' => $this->l('Non')],
                        ],
                    ],
                    [
                        'type' => 'switch',
                        'label' => $this->l('Tester la connexion à l’enregistrement'),
                        'name' => 'TZP_SYNC_TEST',
                        'is_bool' => true,
                        'values' => [
                            ['id' => 'test_on', 'value' => 1, 'label' => $this->l('Oui')],
                            ['id' => 'test_off', 'value' => 0, 'label' => $this->l('Non')],
                        ],
                    ],
                ],
                'submit' => [
                    'title' => $this->l('Enregistrer'),
                    'class' => 'btn btn-default pull-right',
                ],
            ],
        ];

        $helper = new HelperForm();
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
        $helper->default_form_language = (int)Configuration::get('PS_LANG_DEFAULT');
        $helper->allow_employee_form_lang = (int)Configuration::get('PS_LANG_DEFAULT');
        $helper->title = $this->displayName;
        $helper->submit_action = 'submitTzpCatalogSync';
        $helper->fields_value = [
            'TZP_SYNC_ENABLED' => Configuration::get('TZP_SYNC_ENABLED'),
            'TZP_SYNC_URL' => Configuration::get('TZP_SYNC_URL'),
            'TZP_SYNC_API_KEY' => Configuration::get('TZP_SYNC_API_KEY'),
            'TZP_SYNC_DEACTIVATE_OOS' => Configuration::get('TZP_SYNC_DEACTIVATE_OOS'),
            'TZP_SYNC_TEST' => 0,
        ];

        return $helper->generateForm([$fields]);
    }

    public function hookActionProductSave($params)
    {
        $idProduct = (int)($params['id_product'] ?? 0);
        if ($idProduct <= 0 && !empty($params['product']) && Validate::isLoadedObject($params['product'])) {
            $idProduct = (int)$params['product']->id;
        }
        if ($idProduct > 0) {
            $this->pushProduct($idProduct);
        }
    }

    public function hookActionProductDelete($params)
    {
        $idProduct = (int)($params['id_product'] ?? 0);
        if ($idProduct <= 0) {
            return;
        }
        $payload = [
            'action' => 'deactivate',
            'prestashop_id' => $idProduct,
        ];
        // Si le produit n'est plus lisible, on envoie au moins l'id PS.
        $this->sendRequest($payload);
    }

    public function hookActionUpdateQuantity($params)
    {
        $idProduct = (int)($params['id_product'] ?? 0);
        if ($idProduct > 0) {
            $this->pushProduct($idProduct);
        }
    }

    protected function pushProduct($idProduct)
    {
        if (!(int)Configuration::get('TZP_SYNC_ENABLED')) {
            return;
        }

        $idLang = (int)Configuration::get('PS_LANG_DEFAULT');
        $product = new Product($idProduct, false, $idLang);
        if (!Validate::isLoadedObject($product)) {
            return;
        }

        $link = new Link();
        $productUrl = $link->getProductLink($product);
        $imageUrl = '';
        $cover = Product::getCover($idProduct);
        if (!empty($cover['id_image'])) {
            $imageUrl = $link->getImageLink(
                $product->link_rewrite,
                (int)$cover['id_image'],
                'home_default'
            );
            if ($imageUrl !== '' && !preg_match('#^https?://#i', $imageUrl)) {
                $imageUrl = (Configuration::get('PS_SSL_ENABLED') ? 'https://' : 'http://') . $imageUrl;
            }
        }

        $brand = '';
        if ((int)$product->id_manufacturer > 0) {
            $brand = Manufacturer::getNameById((int)$product->id_manufacturer);
        }

        $qty = (int)StockAvailable::getQuantityAvailableByProduct($idProduct);
        $isActive = (int)$product->active === 1;
        if ((int)Configuration::get('TZP_SYNC_DEACTIVATE_OOS') && $qty <= 0) {
            $isActive = false;
        }

        $price = Product::getPriceStatic($idProduct, true, null, 2);

        $payload = [
            'action' => 'sync',
            'prestashop_id' => (int)$product->id,
            'name' => (string)$product->name,
            'brand' => (string)$brand,
            'price' => $price,
            'image_url' => $imageUrl,
            'product_url' => $productUrl,
            'is_active' => $isActive ? 1 : 0,
            'reference' => (string)$product->reference,
            'quantity' => $qty,
        ];

        $this->sendRequest($payload);
    }

    protected function sendRequest(array $payload)
    {
        $url = trim((string)Configuration::get('TZP_SYNC_URL'));
        $apiKey = trim((string)Configuration::get('TZP_SYNC_API_KEY'));
        if ($url === '' || $apiKey === '') {
            return ['ok' => false, 'error' => 'URL ou clé API manquante.'];
        }

        $body = json_encode($payload);
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $apiKey,
                ],
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_TIMEOUT => 8,
                CURLOPT_CONNECTTIMEOUT => 4,
            ]);
            $response = curl_exec($ch);
            $errno = curl_errno($ch);
            curl_close($ch);
            if ($errno) {
                return ['ok' => false, 'error' => 'Erreur cURL #' . $errno];
            }
            $decoded = json_decode((string)$response, true);
            return is_array($decoded) ? $decoded : ['ok' => false, 'error' => 'Réponse invalide'];
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\nAuthorization: Bearer {$apiKey}\r\n",
                'content' => $body,
                'timeout' => 8,
                'ignore_errors' => true,
            ],
        ]);
        $response = @file_get_contents($url, false, $context);
        $decoded = json_decode((string)$response, true);
        return is_array($decoded) ? $decoded : ['ok' => false, 'error' => 'Réponse invalide'];
    }
}
