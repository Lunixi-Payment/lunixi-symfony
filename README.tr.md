<p align="center"><a href="https://lunixi.com"><img src="https://raw.githubusercontent.com/Lunixi-Payment/lunixi-symfony/main/.github/assets/lunixi-logo.svg" alt="Lunixi" height="64"></a></p>

# Lunixi Symfony Bundle

[English](README.md) · [Dokümantasyon](https://docs.lunixi.com/tr/sdks/symfony) · [MIT](LICENSE)

Mevcut PHP SDK’yı Symfony servislerine bağlar; isteğe bağlı kalıcı ödeme akışını siparişlerle aynı Doctrine bağlantısında çalıştırır. Rotalar, müşteri yetkisi, CSRF, sipariş/stok/fatura politikaları uygulamanıza aittir. Paket kendiliğinden herkese açık bir ödeme rotası oluşturmaz.

PHP8.1+, desteklenen Symfony6.4/7.4/8.1 ve PHP SDK ^1.0.1 gerekir. Yerelde Symfony8.1.10, DBAL4.5.0, PHP8.4, SQLite ve MariaDB doğrulandı. PostgreSQL yolu aynı DBAL sözleşmesini kullanır; yerel PostgreSQL kabulü çalıştırılmadı. [Doğrulama kaydı](docs/VERIFICATION.md) gerçek kapsamı açıklar.

## Kurulum

[1.0.0 sürümü](https://packagist.org/packages/lunixi/symfony-bundle) ve [PHP SDK 1.0.1 bağımlılığı](https://packagist.org/packages/lunixi/php-sdk) Packagist üzerinde yayımlandı; aşağıdaki komutla kurun. Kanonik string para sözleşmesi için [geçiş rehberini](MIGRATION-1.0.md) uygulayın:

```sh
composer require "lunixi/symfony-bundle:^1.0"
```

Composer yayımlanmış PHP SDK bağımlılığını çözer; normal kurulum VCS tanımı veya
yerel SDK kaynağı gerektirmez. İnceleme için [v1.0.0 kaynak etiketi](https://github.com/Lunixi-Payment/lunixi-symfony/tree/v1.0.0) kullanılabilir.

`config/bundles.php` içine `Lunixi\Symfony\LunixiBundle::class => ['all' => true]` ekleyin. `config/packages/lunixi.yaml`:

```yaml
lunixi:
  key_id: '%env(LUNIXI_KEY_ID)%'
  private_key: '%env(LUNIXI_PRIVATE_KEY_PEM)%'
  commerce_enabled: true
  connection_service: 'doctrine.dbal.default_connection'
  merchant_id: '%env(LUNIXI_MERCHANT_ID)%'
  webhook_secret: '%env(LUNIXI_WEBHOOK_SECRET)%'
  storage_key: '%env(base64:LUNIXI_STORAGE_KEY)%'
```

Anahtarları Symfony Secrets/sunucu secret manager ile sağlayın. `LUNIXI_STORAGE_KEY`, token şifrelemesi için ayrı ve yedeklenen 32 baytlık rastgele anahtarın base64 karşılığıdır. API özel anahtarı veya webhook secret’ından türetilmez. Varsayılan gateway `https://api-gateway.lunixi.com`; TEST/LIVE ayrımını üye işyeri anahtarı belirler. `allowed_origins` yalnız operatörün onayladığı HTTPS origin’leri içermelidir. `cache_pool` varsayılanı özel `cache.app` havuzudur; bearer token’ı HTTP cache’e koymayın.

DoctrineBundle bağlantısı varsa onu kullanın. Aksi halde bir DBAL bağlantı servisi tanımlayın; ödeme defteri ve sipariş yazıları **aynı servisi/bağlantıyı** kullanmalıdır. SQLite yapılandırması ve tüm servis seçenekleri [İngilizce rehberde](README.md) vardır. Checkout oluşturmayı dış bir transaction içine almayın; işlem anahtarı ağ çağrısından önce kalıcılaşmalıdır.

Dağıtım sırasında `php bin/console lunixi:ledger:install` çalıştırın. DDL webhook sırasında çalışmaz; mevcut finansal geçmiş silinmez.

## Ödeme ve bildirim

`LunixiClient` PHP SDK’nın tüm belgelenmiş servislerini sunar. `CheckoutService`, `MerchantContext`, `DoctrineLedgerStore` ve `CacheTokenStore` otomatik bağlanabilir. Müşteri yetkisini kontrol edip tutarı kendi veritabanınızdan okuyun:

```php
$order = new Lunixi\Sdk\Commerce\OrderSnapshot(
    $merchant->merchantId(), 'order-1001',
    Lunixi\Sdk\Commerce\Money::minor('100.50', 2), 'TRY'
);
$session = $checkout->start('order-1001', $order, 'https://shop.example/payment/return');
```

Varsayılan gömülü form kendi sitenizde açılır: sabit resmi checkout bundle’ını yükleyin, `#lunixi-checkout-form` oluşturun, `$session->browserConfig()` JSON’unu `Lunixi.checkout.create` ile kullanıp mount edin. CSP nonce’unu koruyun, token yanıtını `private, no-store` gönderin. PAN/CVV toplamayın veya gateway HTML’i basmayın. İsteğe bağlı yönlendirme için deneme oluşmadan dördüncü argümanı `true` seçin ve `$session->hostedUrl()` değerini kullanın. Bu, backend yanıtındaki doğrulanmış `paymentPageUrl` adresidir ve denemeyle şifreli saklanır; gömülü token’dan URL üretilmez. Eski kayıtta URL yoksa yeni anahtar/ödeme oluşturmayın; mutabakat yapın. Yöntem ve taksitler Lunixi panelinden yönetilir; beşinci argüman provizyonu açar ve siparişi ödenmiş yapmaz.

Ham POST webhook’u `$merchant->verifyWebhook($request, $checkout)` ile doğrulayın; yalnız bu rotayı tarayıcı CSRF kontrolünden istisna tutun. İmzalı `data.orderId` sadece aday kaydı bulur. `$checkout->reconcile($orderKey, $applyNativeOrder, $event)` sunucudan ödeme sorgular; üye işyeri, ödeme/sipariş kimliği, para birimi ve tahsilat sayaçlarını eşleştirir. Çoklu kartta tek bacak yeterli değildir; tamamlanmış ana ödeme toplamı gerekir. Tarayıcı dönüşü hiçbir zaman ödeme kanıtı değildir.

Uygulama callback’i aynı transaction içinde güncel siparişi kilitleyip başlangıç tutarı/para birimini kontrol etmeli, stok/faturayı yalnız bir kez uygulamalı ve mevcut fraud/manuel bekletmeyi korumalıdır. Bildirim ve e-postalar transaction outbox’ından commit sonrası gönderilir. Tekrar olay çalışmaz; aynı olay kimliğiyle farklı içerik reddedilir. İşlem hatasında hem olay hem sipariş geri alınır. Bekleyen işlemler için zamanlanmış mutabakat gerekir.

## İade ve provizyon

Yetki/CSRF kontrolünden sonra kalıcı yerel işlem kimliğiyle `mutate($orderKey, $operationId, 'refund', $amountMinor, $applyNativeOrder)` çağırın. `operationStatus` sonucu yalnız `confirmed` olduğunda başarı gösterin. `pending` veya `uncertain` sonucu yeni anahtarla tekrarlamayın; aynı kimlik ve gövdeyi kullanın. `capture` tutar alır, `void` için tutar `null` olur. Kısmi çekim siparişi tamamlamaz; iade yalnız çekilen bakiye üzerinden yapılır. Ürün/KDV alacak belgeleri, stok iadesi ve fulfillment uygulama politikanızdır.

## Kontroller

`composer install`, `vendor/bin/phpunit`, `vendor/bin/phpstan analyse --no-progress`, `composer audit --locked` çalıştırılır. Gerçek MariaDB concurrency/rollback testleri için yalnız açıkça ayrılmış `lunixi_` test veritabanını `LUNIXI_TEST_DB_FILE` ile verin. Gateway çağrıları sentetiktir; gerçek tahsilat yapılmaz. [Doğrulama](docs/VERIFICATION.md) · [Güvenlik](SECURITY.md) · [Katkı](CONTRIBUTING.md).

Finansal sonucu belirsiz işlemler incelemede tutulur. `FAILED` tek başına yeni denemeye izin vermez; herkese açık ödeme sorgusu kesin sağlayıcı sonuç kanıtını döndürmez. Sonuçlanmamış finansal tekrarlar 23 saat sonra durur; kimliği doğrulanmış GET mutabakatı devam eder. Zaman aşımı, form token süresi veya tarayıcı dönüşü yeni finansal anahtar üretmez.
