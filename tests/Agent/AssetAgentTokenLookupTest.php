<?php

namespace Tests\Agent;

use App\Models\Asset;
use App\Models\AssetAgentToken;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\AgentTestCase;

/** Pencarian token agent harus O(1): armada ratusan laptop melapor tiap 5 menit. */
class AssetAgentTokenLookupTest extends AgentTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

    }

    public function test_only_one_bcrypt_check_is_made_no_matter_how_many_tokens_exist(): void
    {
        $plain = [];
        foreach (range(1, 25) as $i) {
            [, $plain[$i]] = AssetAgentToken::issueFor($this->makeAsset(), null);
        }

        Hash::shouldReceive('check')->once()->andReturn(true);   // implementasi lama memanggilnya hingga 25 kali

        $found = AssetAgentToken::findActiveMatching($plain[24]);

        $this->assertNotNull($found);
        $this->assertSame(1, $found->asset->agentTokens()->count());
    }

    public function test_a_wrong_or_empty_token_is_rejected_without_any_bcrypt_work(): void
    {
        AssetAgentToken::issueFor($this->makeAsset(), null);

        Hash::shouldReceive('check')->never();

        $this->assertNull(AssetAgentToken::findActiveMatching(str_repeat('x', 40)));
        $this->assertNull(AssetAgentToken::findActiveMatching(''));
    }

    public function test_revoked_tokens_are_not_matched(): void
    {
        $asset = $this->makeAsset();
        [$token, $plain] = AssetAgentToken::issueFor($asset, null);
        $token->update(['revoked_at' => now()]);

        $this->assertNull(AssetAgentToken::findActiveMatching($plain));
    }

    public function test_bcrypt_is_still_verified_for_the_matching_row(): void
    {
        [$token, $plain] = AssetAgentToken::issueFor($this->makeAsset(), null);
        $token->update(['token_hash' => Hash::make('token-lain-yang-tidak-sama')]);   // hash tidak sesuai lookup

        $this->assertNull(AssetAgentToken::findActiveMatching($plain));
    }

    public function test_tokens_created_before_the_lookup_column_still_work_and_are_upgraded(): void
    {
        $asset = $this->makeAsset();
        $plain = str_repeat('a', 40);
        DB::table('asset_agent_tokens')->insert([
            'asset_id' => $asset->id, 'token_hash' => Hash::make($plain), 'token_lookup' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $first = AssetAgentToken::findActiveMatching($plain);

        $this->assertNotNull($first);
        $this->assertSame(AssetAgentToken::lookupHash($plain), $first->fresh()->token_lookup);

        // pemakaian berikutnya lewat jalur cepat: satu bcrypt saja
        Hash::shouldReceive('check')->once()->andReturn(true);
        $this->assertNotNull(AssetAgentToken::findActiveMatching($plain));
    }

    public function test_the_heartbeat_endpoint_still_authenticates(): void
    {
        $asset = $this->makeAsset();
        [, $plain] = AssetAgentToken::issueFor($asset, null);

        $this->withToken($plain)->postJson('/api/agent/v1/heartbeat', ['hostname' => 'X'])->assertOk()->assertJson(['status' => 'ok']);
        $this->withToken(str_repeat('z', 40))->postJson('/api/agent/v1/heartbeat', ['hostname' => 'X'])->assertUnauthorized();
    }
}
