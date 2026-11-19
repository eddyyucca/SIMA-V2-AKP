<?php

namespace Tests\Qa;

use App\Models\Asset;
use App\Models\AssetModel;
use Tests\QaTestCase;

class Qa64_ModelPhotoFallbackTest extends QaTestCase
{
    public function test_asset_uses_its_own_photo_then_falls_back_to_the_model_photo(): void
    {
        $a = Asset::whereNotNull('asset_model_id')->firstOrFail();
        $a->update(['photo_path' => null]);
        AssetModel::whereKey($a->asset_model_id)->update(['photo_path' => 'models/contoh.png']);

        $this->assertSame('models/contoh.png', $a->fresh()->displayPhoto());          // tanpa foto sendiri -> foto model

        $a->update(['photo_path' => 'assets/photos/sendiri.png']);
        $this->assertSame('assets/photos/sendiri.png', $a->fresh()->displayPhoto());  // foto sendiri menang

        $a->update(['photo_path' => null]);
        AssetModel::whereKey($a->asset_model_id)->update(['photo_path' => null]);
        $this->assertNull($a->fresh()->displayPhoto());
    }
}
