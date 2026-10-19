<?php

namespace App\Actions\AllowedTargets;

use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Models\AllowedTarget;
use App\Models\User;
use App\Support\TargetNetworkPolicy;
use Illuminate\Validation\ValidationException;

class CreateAllowedTargetAction
{
    /**
     * @param  array{host: string, port?: int|null, note?: string|null}  $data
     */
    public function execute(User $user, array $data): AllowedTarget
    {
        $host = strtolower(rtrim(trim($data['host']), '.'));
        $port = $data['port'] ?? null;

        $this->assertNotPlatformService($host);
        $this->assertReachableNetwork($host);
        $this->assertCanonicalIpv4($host);

        $duplicateExists = AllowedTarget::query()
            ->where('host', $host)
            ->where('port', $port)
            ->exists();

        if ($duplicateExists) {
            throw new ApiException(
                ErrorCode::Conflict,
                'Host và cổng này đã có trong allowlist.',
                ['host' => $host, 'port' => $port],
            );
        }

        return $user->allowedTargets()->create([
            'host' => $host,
            'port' => $port,
            'note' => $data['note'] ?? null,
        ]);
    }

    /**
     * The platform's own Compose services (postgres, web, worker, ai-service...) are never test
     * targets. The executor would refuse them anyway once they resolve to a private address, but an
     * ADMIN gets a clear validation error on `host` (422 VALIDATION_FAILED, M04 form field) at creation
     * instead of a failing run (S14-02). Benchmark services stay allowed.
     */
    private function assertNotPlatformService(string $host): void
    {
        if (TargetNetworkPolicy::isPlatformServiceName($host)) {
            throw ValidationException::withMessages([
                'host' => "\"{$host}\" là dịch vụ nội bộ của nền tảng (cơ sở dữ liệu, web, worker, ai-service...), không phải API cần kiểm thử, nên không được thêm vào allowlist. Chỉ thêm host của API đích, ví dụ các dịch vụ benchmark.",
            ]);
        }
    }

    /**
     * Refuse literals that can only point at metadata/link-local addresses, and, when private
     * networks are blocked (public server, S14-02), at loopback/private ones and at the well-known
     * names of the host, the Docker gateway and cloud metadata. Legacy IPv4 notations (2130706433,
     * 0x7f000001, 017700000001, 127.1) are decoded first, so they cannot pass as hostnames (S13-03).
     * Hostnames are checked again after DNS resolution on every request (TargetAllowlistValidator).
     */
    private function assertReachableNetwork(string $host): void
    {
        $blockPrivate = (bool) config('ait.target_client.block_private_networks', false);
        // Both readings of an ambiguous literal (010.0.0.1 is 10.0.0.1 for inet_pton, 8.0.0.1 for curl).
        $ips = array_filter([TargetNetworkPolicy::normalize($host), TargetNetworkPolicy::parseLegacyIpv4($host)]);
        $blocked = array_filter($ips, fn (string $ip): bool => TargetNetworkPolicy::isBlocked($ip, $blockPrivate)) !== []
            || ($blockPrivate && TargetNetworkPolicy::isLoopbackName($host));

        if ($blocked) {
            throw new ApiException(
                ErrorCode::TargetNotAllowed,
                $blockPrivate
                    ? 'Không được thêm địa chỉ loopback, mạng riêng, link-local, metadata hay tên nội bộ của máy chủ (host.docker.internal, metadata.google.internal...) vào allowlist trên máy chủ công khai.'
                    : 'Không được thêm địa chỉ link-local, metadata hay multicast vào allowlist.',
                ['host' => $host],
            );
        }
    }

    /**
     * A host whose last label is a number is read as an IPv4 address by HTTP clients, never as a
     * DNS name. Only the canonical dotted-quad form is stored, so the allowlist shows the address
     * the executor will really reach (422 VALIDATION_FAILED on `host`, S13-03).
     */
    private function assertCanonicalIpv4(string $host): void
    {
        $normalized = TargetNetworkPolicy::normalize($host);
        // IPv6 literals have several valid spellings; an IPv4 one must already be canonical
        // (no leading zeros, which some clients read as octal).
        if (($normalized !== null && (str_contains($host, ':') || $normalized === $host))
            || ($normalized === null && ! TargetNetworkPolicy::endsInNumber($host))) {
            return;
        }

        $ip = TargetNetworkPolicy::parseLegacyIpv4($host);
        throw ValidationException::withMessages([
            'host' => $ip !== null
                ? "\"{$host}\" là cách viết khác của địa chỉ IPv4 {$ip}. Hãy nhập địa chỉ IPv4 ở dạng chuẩn a.b.c.d."
                : "\"{$host}\" không phải hostname hợp lệ: nhãn cuối là số nhưng không phải địa chỉ IPv4. Hãy nhập địa chỉ IPv4 ở dạng chuẩn a.b.c.d hoặc một hostname.",
        ]);
    }
}
