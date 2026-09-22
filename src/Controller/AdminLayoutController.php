<?php

declare(strict_types=1);
namespace App\Controller;

use App\Entity\ContentEntry;
use App\Entity\PageLayout;
use App\Layout\LayoutRenderer;
use App\Layout\LayoutImages;
use App\Layout\LayoutStore;
use App\Layout\LayoutValidator;
use App\Service\AuditLogger;
use App\Theme\ThemeRegistry;
use App\Widget\WidgetRegistry;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\OptimisticLockException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/layout')]
#[IsGranted('CMS_SETTINGS_MANAGE')]
final class AdminLayoutController extends AbstractController
{
    public function __construct(private readonly LayoutStore $store, private readonly LayoutValidator $validator, private readonly LayoutRenderer $renderer, private readonly ThemeRegistry $themes, private readonly WidgetRegistry $widgets, private readonly EntityManagerInterface $em, private readonly AuditLogger $audit, private readonly LayoutImages $images) {}

    #[Route('/{context}', name: 'app_admin_layout', defaults: ['context'=>'home'], requirements: ['context'=>'home|page-[1-9][0-9]*'], methods: ['GET'])]
    public function edit(string $context): Response
    {
        if (!$this->store->exists($context)) throw $this->createNotFoundException();
        $document=$this->store->load($context);
        $themes=[];
        foreach($this->themes->choices() as $label=>$key) $themes[]=['key'=>$key,'label'=>$label,'regions'=>$this->themes->get($key)->regions];
        $available=[];
        foreach($this->widgets->availableDefinitions() as $definition) $available[]=['key'=>$definition->key,'label'=>$definition->label,'regions'=>$definition->regions,'multiple'=>$definition->multiple,'schema'=>$this->validator->widgetSchema($definition->key)];
        $pages=$this->em->getRepository(ContentEntry::class)->findBy(['type'=>ContentEntry::TYPE_PAGE],['title'=>'ASC'],200);
        $response=$this->render('admin/layout/edit.html.twig',[
            'context'=>$context,'pages'=>$pages,
            'editor'=>['document'=>$document->toArray(),'version'=>$this->store->record($context)?->getVersion()??0,'themes'=>$themes,'widgets'=>$available,'images'=>$this->images->choices(),'optionSchema'=>$this->validator->optionSchema(),'widgetSchema'=>$this->validator->widgetSchema()],
        ]);
        $response->headers->set('Cache-Control','private, no-store');
        return $response;
    }

    #[Route('/{context}/{operation}', name: 'app_admin_layout_write', requirements: ['context'=>'home|page-[1-9][0-9]*','operation'=>'save|preview'], methods: ['POST'])]
    public function write(string $context, string $operation, Request $request): Response
    {
        if (!$this->store->exists($context)) throw $this->createNotFoundException();
        if (!$this->isCsrfTokenValid('layout-'.$context,$request->headers->get('X-CSRF-Token'))) throw $this->createAccessDeniedException('Ungültiges CSRF-Token.');
        if (strlen($request->getContent())>131072) return $this->error('Layout zu groß.',413);
        try {
            $input=json_decode($request->getContent(),true,32,JSON_THROW_ON_ERROR);
            if (!is_array($input) || !is_int($input['version']??null) || !is_array($input['document']??null) || array_diff(array_keys($input),['version','document'])!==[]) throw new \DomainException('Ungültige Anfrage.');
            $record=$this->store->record($context);
            if (($record?->getVersion()??0)!==$input['version']) return $this->error('Die Seite wurde inzwischen geändert. Bitte neu laden; deine Änderungen wurden nicht überschrieben.',409);
            $previous=$this->store->load($context);
            $document=$this->validator->validate($input['document'],$previous,($input['document']['theme']??null)!==$previous->theme);
            $this->images->resolve($document,true);
            if ($operation==='preview') {
                $entry=$context==='home'?null:$this->em->find(ContentEntry::class,(int)substr($context,5));
                $response=$this->render('layout/preview.html.twig',['portal'=>$this->renderer->view($document),'entry'=>$entry]);
                $response->headers->set('Cache-Control','private, no-store');
                $response->headers->set('X-Robots-Tag','noindex, nofollow');
                return $response;
            }
            $record??=new PageLayout($context);
            $record->replace($document->toArray());
            $this->em->wrapInTransaction(function () use ($record,$context): void {
                $this->em->persist($record);
                $this->audit->record('layout.saved',PageLayout::class,null,'Seitenlayout gespeichert',['context'=>$context]);
            });
            $response=new JsonResponse(['document'=>$document->toArray(),'version'=>$record->getVersion(),'notices'=>$document->notices]);
            $response->headers->set('Cache-Control','private, no-store');
            return $response;
        } catch (OptimisticLockException|UniqueConstraintViolationException) {
            return $this->error('Gleichzeitige Änderung erkannt. Bitte neu laden.',409);
        } catch (\JsonException|\DomainException $exception) {
            return $this->error($exception instanceof \JsonException?'Ungültiges JSON.':$exception->getMessage(),422);
        }
    }
    private function error(string $message,int $status): JsonResponse { return new JsonResponse(['error'=>$message],$status,['Cache-Control'=>'private, no-store']); }
}
