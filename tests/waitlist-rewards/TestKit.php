<?php
final class T {
    public static int $pass = 0, $fail = 0; public static array $failures = []; private static string $section = '';
    public static function section(string $s): void { self::$section = $s; echo "\n$s\n"; }
    public static function ok(bool $c, string $msg): void {
        if ($c) { self::$pass++; echo "  PASS  $msg\n"; } else { self::$fail++; self::$failures[] = self::$section . ' :: ' . $msg; echo "  FAIL  $msg\n"; }
    }
    public static function eq($got, $want, string $msg): void {
        $c = $got === $want; self::ok($c, $msg . ($c ? '' : '   (got ' . var_export($got, true) . ', want ' . var_export($want, true) . ')'));
    }
}
