<?php

declare(strict_types=1);

namespace App\VideoPlayerBranding;

use Symfony\Component\HttpFoundation\Request;

final class BrandingOptions
{
    /** @return array{mode:string,label:string,color:string,position:string} */
    public function parse(Request $request, string $playbackMode): array
    {
        $mode = $request->request->getString('branding', 'off');
        $label = trim($request->request->getString('brand_label', 'Gaming CMS'));
        $color = $request->request->getString('brand_color', 'light');
        $position = $request->request->getString('brand_position', 'top-right');

        if (!in_array($mode, ['off', 'own'], true) || !in_array($color, ['light', 'dark'], true)
            || !in_array($position, ['top-left', 'top-right', 'bottom-left', 'bottom-right'], true)
            || mb_strlen($label) > 40 || ($mode === 'own' && $label === '')) {
            throw new \InvalidArgumentException('Ungültige Branding-Einstellungen.');
        }
        if (!in_array($playbackMode, ['video', 'hls'], true)) {
            if ($mode !== 'off') {
                throw new \InvalidArgumentException('Das Branding eines Anbieter-Players kann hier nicht geändert werden.');
            }
            $mode = 'provider';
        }

        return ['mode' => $mode, 'label' => $label, 'color' => $color, 'position' => $position];
    }
}
