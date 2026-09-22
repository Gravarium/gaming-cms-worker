<?php

declare(strict_types=1);
namespace App\Twig;

use App\Layout\LayoutRenderer;
use App\Layout\LayoutStore;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class LayoutExtension extends AbstractExtension
{
    public function __construct(private readonly LayoutStore $store, private readonly LayoutRenderer $renderer) {}
    public function getFunctions(): array { return [new TwigFunction('cms_page_layout',$this->page(...))]; }
    /** @return array<string, mixed>|null */
    public function page(int $id): ?array
    {
        $context='page-'.$id;
        return $this->store->record($context)===null?null:$this->renderer->view($this->store->load($context));
    }
}
