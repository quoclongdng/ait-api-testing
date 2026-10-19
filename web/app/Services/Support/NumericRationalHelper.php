<?php

namespace App\Services\Support;

trait NumericRationalHelper
{
    /**
     * Parse a numeric value into a reduced rational fraction [numerator, denominator].
     *
     * @return array{0: numeric-string, 1: numeric-string}
     */
    protected function parseDecimalRational(float|int|string $val): array
    {
        if (is_int($val) || (is_string($val) && preg_match('/^-?\d+$/', trim($val)))) {
            $n = trim((string) $val);
            if ($n === '0' || $n === '-0') {
                return ['0', '1'];
            }

            /** @var numeric-string $n */
            return [$n, '1'];
        }

        $str = strtolower(trim((string) json_encode((float) $val)));
        if (str_contains($str, 'e')) {
            [$mantissa, $expStr] = explode('e', $str);
            $exp = (int) $expStr;
        } else {
            $mantissa = $str;
            $exp = 0;
        }

        $sign = '';
        if (str_starts_with($mantissa, '-')) {
            $sign = '-';
            $mantissa = substr($mantissa, 1);
        }

        if (str_contains($mantissa, '.')) {
            [$intPart, $decPart] = explode('.', $mantissa);
            $decPart = rtrim($decPart, '0');
            if ($decPart === '') {
                $digits = ltrim($intPart, '0');
            } else {
                $digits = ltrim($intPart.$decPart, '0');
                $exp -= strlen($decPart);
            }
        } else {
            $digits = ltrim($mantissa, '0');
        }

        if ($digits === '' || ! is_numeric($digits)) {
            return ['0', '1'];
        }

        $pow = bcpow('10', (string) abs($exp));
        if ($exp >= 0) {
            $num = bcmul($digits, $pow);
            $den = '1';
        } else {
            $num = $digits;
            $den = $pow;
        }

        $g = $this->rationalGcd($num, $den);
        /** @var numeric-string $resNum */
        $resNum = bcdiv($num, $g);
        /** @var numeric-string $resDen */
        $resDen = bcdiv($den, $g);

        /** @var numeric-string $signedNum */
        $signedNum = $sign !== '' && $resNum !== '0' ? $sign.$resNum : $resNum;

        return [$signedNum, $resDen];
    }

    /**
     * Compute GCD of two large integer strings using bcmath.
     *
     * @param  numeric-string  $a
     * @param  numeric-string  $b
     * @return numeric-string
     */
    protected function rationalGcd(string $a, string $b): string
    {
        /** @var numeric-string $cleanA */
        $cleanA = ltrim($a, '-');
        /** @var numeric-string $cleanB */
        $cleanB = ltrim($b, '-');
        while (bccomp($cleanB, '0') !== 0) {
            /** @var numeric-string $t */
            $t = bcmod($cleanA, $cleanB);
            $cleanA = $cleanB;
            $cleanB = $t;
        }

        return $cleanA === '0' ? '1' : $cleanA;
    }

    /**
     * Compute the least common multiple (LCM) of two positive multiples.
     */
    protected function calculateLcm(float|int|string $a, float|int|string $b): float|int
    {
        if (! is_numeric($a) || ! is_numeric($b)) {
            return 1;
        }

        $r1 = is_int($a) || (is_string($a) && ctype_digit(ltrim($a, '-')))
            ? [ltrim((string) abs((int) $a), '-'), '1']
            : $this->parseDecimalRational(abs((float) $a));
        $r2 = is_int($b) || (is_string($b) && ctype_digit(ltrim($b, '-')))
            ? [ltrim((string) abs((int) $b), '-'), '1']
            : $this->parseDecimalRational(abs((float) $b));

        /** @var numeric-string $n1Check */
        $n1Check = $r1[0];
        /** @var numeric-string $n2Check */
        $n2Check = $r2[0];

        if (bccomp($n1Check, '0') === 0) {
            return is_int($b) ? abs($b) : abs((float) $b);
        }
        if (bccomp($n2Check, '0') === 0) {
            return is_int($a) ? abs($a) : abs((float) $a);
        }

        // lcm(n1/d1, n2/d2) = lcm(n1, n2) / gcd(d1, d2)
        // lcm(n1, n2) = (n1 * n2) / gcd(n1, n2)
        /** @var numeric-string $n1 */
        $n1 = $r1[0];
        /** @var numeric-string $d1 */
        $d1 = $r1[1];
        /** @var numeric-string $n2 */
        $n2 = $r2[0];
        /** @var numeric-string $d2 */
        $d2 = $r2[1];

        $gn = $this->rationalGcd($n1, $n2);
        /** @var numeric-string $prodN */
        $prodN = bcmul($n1, $n2);
        /** @var numeric-string $lcmN */
        $lcmN = bcdiv($prodN, $gn);
        $gcdD = $this->rationalGcd($d1, $d2);

        $g = $this->rationalGcd($lcmN, $gcdD);
        /** @var numeric-string $resN */
        $resN = bcdiv($lcmN, $g);
        /** @var numeric-string $resD */
        $resD = bcdiv($gcdD, $g);

        if ($resD === '1' && bccomp($resN, (string) PHP_INT_MAX) <= 0) {
            return (int) $resN;
        }

        $scale = max(14, strlen($resD) + 2);
        $val = (float) bcdiv($resN, $resD, $scale);

        return is_finite($val) && $val > 0.0 ? $val : (float) bcdiv($resN, $resD, 14);
    }

    /**
     * Compute the smallest positive integer step that is a multiple of $m.
     */
    protected function getIntegerLatticeStep(float|int|string $m): int
    {
        if (! is_numeric($m) || (float) $m <= 0.0 || ! is_finite((float) $m)) {
            return 1;
        }

        [$num, $den] = $this->parseDecimalRational($m);
        if ($num === '0') {
            return 1;
        }

        // An integer k is a multiple of (num / den) with gcd(num, den) = 1
        // iff k is an integer multiple of num.
        // Therefore the smallest positive integer step is num.
        if (bccomp($num, (string) PHP_INT_MAX) <= 0) {
            return max(1, (int) $num);
        }

        return PHP_INT_MAX;
    }

    /**
     * Check if a numeric value conforms to multipleOf constraint with exact rational divisibility.
     */
    protected function isConformingMultiple(int|float $value, int|float|string $multipleOf): bool
    {
        if (! is_numeric($multipleOf) || (float) $multipleOf <= 0.0) {
            return false;
        }

        $rVal = $this->parseDecimalRational($value);
        $rMult = $this->parseDecimalRational($multipleOf);
        if ($rMult[0] === '0') {
            return false;
        }

        /** @var numeric-string $nv */
        $nv = $rVal[0];
        /** @var numeric-string $dv */
        $dv = $rVal[1];
        /** @var numeric-string $nm */
        $nm = $rMult[0];
        /** @var numeric-string $dm */
        $dm = $rMult[1];

        /** @var numeric-string $num */
        $num = bcmul($nv, $dm);
        /** @var numeric-string $den */
        $den = bcmul($dv, $nm);
        if ($den !== '0' && bcmod($num, $den) === '0') {
            return true;
        }

        $ratio = (float) $value / (float) $multipleOf;

        return is_finite($ratio) && abs($ratio - round($ratio)) <= 1e-9;
    }

    protected function getDecimalScale(float $val): int
    {
        $str = strtolower((string) json_encode($val));
        if (str_contains($str, 'e')) {
            $parts = explode('e', $str);
            $decPart = explode('.', $parts[0])[1] ?? '';
            $exp = (int) $parts[1];

            return max(0, strlen($decPart) - $exp);
        }
        if (str_contains($str, '.')) {
            return strlen(explode('.', $str)[1] ?? '');
        }

        return 0;
    }

    /**
     * Compute ceiling of quotient $dividend / $divisor using bcmath.
     *
     * @param  numeric-string  $dividend
     * @param  numeric-string  $divisor
     * @return numeric-string
     */
    protected function quotientCeil(string $dividend, string $divisor): string
    {
        /** @var numeric-string $q */
        $q = bcdiv($dividend, $divisor, 0);
        $rem = bcmod($dividend, $divisor);
        if ($rem !== '0' && bccomp($dividend, '0', 0) > 0) {
            /** @var numeric-string */
            return bcadd($q, '1', 0);
        }

        return $q;
    }

    /**
     * Compute floor of quotient $dividend / $divisor using bcmath.
     *
     * @param  numeric-string  $dividend
     * @param  numeric-string  $divisor
     * @return numeric-string
     */
    protected function quotientFloor(string $dividend, string $divisor): string
    {
        /** @var numeric-string $q */
        $q = bcdiv($dividend, $divisor, 0);
        $rem = bcmod($dividend, $divisor);
        if ($rem !== '0' && bccomp($dividend, '0', 0) < 0) {
            /** @var numeric-string */
            return bcsub($q, '1', 0);
        }

        return $q;
    }

    /**
     * Compute exact integer ceiling of any numeric value.
     *
     * @return numeric-string|null
     */
    protected function integerCeil(mixed $value): ?string
    {
        if ($value === null || ! is_numeric($value)) {
            return null;
        }
        if (is_int($value)) {
            return (string) $value;
        }
        [$num, $den] = $this->parseDecimalRational($value);

        return $this->quotientCeil($num, $den);
    }

    /**
     * Compute exact integer floor of any numeric value.
     *
     * @return numeric-string|null
     */
    protected function integerFloor(mixed $value): ?string
    {
        if ($value === null || ! is_numeric($value)) {
            return null;
        }
        if (is_int($value)) {
            return (string) $value;
        }
        [$num, $den] = $this->parseDecimalRational($value);

        return $this->quotientFloor($num, $den);
    }
}
