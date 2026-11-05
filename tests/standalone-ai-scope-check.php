<?php
namespace Illuminate\Support { class Str { public static function ascii(string $text): string { return iconv('UTF-8', 'ASCII//TRANSLIT', $text) ?: $text; } } }
namespace {
require __DIR__.'/../app/Domain/AI/AssistantScope.php';
require __DIR__.'/../app/Domain/AI/AssistantIntent.php';
require __DIR__.'/../app/Domain/AI/Tools/SoftwareCompatibility.php';
$scope = new \App\Domain\AI\AssistantScope();
$intent = new \App\Domain\AI\AssistantIntent();
$cases = [
  ['Me enamoré de mi prima ¿qué hago?', false],
  ['Quiero descargar GTA V en alguna compu ¿servirá alguna?', true],
  ['¿Qué computadora tiene más RAM?', true],
  ['¿Cuáles procesos consumen más memoria?', true],
  ['¿Cómo preparar lasaña?', false],
];
foreach ($cases as [$q, $expected]) {
    $actual = $scope->allows($q);
    if ($actual !== $expected) throw new \RuntimeException("Scope mismatch: $q => ".var_export($actual, true));
}
$read = $intent->readPlan('Quiero descargar GTA V en alguna compu ¿servirá alguna?');
if ($read !== 'software_compatibility') throw new \RuntimeException('Unexpected plan: '.var_export($read,true));
$ref = new \ReflectionClass(\App\Domain\AI\Tools\SoftwareCompatibility::class);
$instance = $ref->newInstanceWithoutConstructor();
$volumes = $ref->getMethod('eligibleVolumes')->invoke($instance, [
  ['removable'=>false, 'mountpoint'=>'C:\\', 'device'=>'C:\\', 'free_bytes'=>70000000000, 'media_type'=>'SSD', 'bus_type'=>'SATA'],
  ['removable'=>false, 'mountpoint'=>'D:\\', 'device'=>'D:\\', 'free_bytes'=>80000000000, 'media_type'=>'SSD', 'bus_type'=>'SATA'],
  ['removable'=>false, 'mountpoint'=>'Z:\\', 'device'=>'Z:\\', 'free_bytes'=>800000000000, 'media_type'=>'SSD', 'bus_type'=>'USB'],
]);
if (count($volumes) !== 2) throw new \RuntimeException('Wrong volume filtering');
$enhanced = $ref->getMethod('evaluateStorage')->invoke($instance, $volumes, 105, true);
if ($enhanced['status'] !== 'fail') throw new \RuntimeException('Aggregated volumes incorrectly');
echo "Standalone checks: 5 scope, 1 intent, 2 storage passed\n";
}
