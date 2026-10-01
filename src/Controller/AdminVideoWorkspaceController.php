<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Entity\Video;
use App\Entity\VideoDiscovery\CreatorProfile;
use App\Entity\VideoDiscovery\VideoDiscoveryProfile;
use App\Entity\VideoDiscovery\VideoLiveStream;
use App\Entity\VideoDiscovery\VideoTag;
use App\Entity\VideoWorkspace\VideoSource;
use App\Service\AuditLogger;
use App\Video\Discovery\VideoModuleAvailability;
use App\VideoWorkspace\CreatorManagement;
use App\VideoWorkspace\ProviderRegistry;
use App\VideoWorkspace\WorkspaceInput;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\String\Slugger\SluggerInterface;

#[Route('/admin/video-workspace')]
#[IsGranted('CMS_VIDEO_MANAGE')]
final class AdminVideoWorkspaceController extends AbstractController
{
    public function __construct(private readonly EntityManagerInterface $em, private readonly VideoModuleAvailability $module, private readonly ProviderRegistry $providers, private readonly SluggerInterface $slugger, private readonly CreatorManagement $creators, private readonly AuditLogger $audit) {}

    #[Route('', name: 'app_admin_video_workspace', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->enabled();
        try { $page = WorkspaceInput::page($request); } catch (\InvalidArgumentException $e) { throw new UnprocessableEntityHttpException($e->getMessage(), $e); }
        return $this->privateResponse($this->render('video_workspace/admin.html.twig', [
            'sources' => $this->em->getRepository(VideoSource::class)->findBy([], ['id' => 'DESC'], 20, ($page - 1) * 20),
            'creators' => $this->em->getRepository(CreatorProfile::class)->findBy([], ['id' => 'DESC'], 20, ($page - 1) * 20),
            'legacyStreams' => $this->em->getRepository(VideoLiveStream::class)->findBy([], ['id' => 'DESC'], 20, ($page - 1) * 20),
            'catalogue' => $this->providers->catalogue(), 'page' => $page,
        ]));
    }

    #[Route('/creators/new', name: 'app_admin_video_workspace_creator_new', methods: ['GET', 'POST'])]
    public function creatorNew(Request $request): Response
    {
        $this->enabled();
        if ($request->isMethod('POST')) {
            $this->csrf($request, 'workspace-creator-new');
            try {
                $name = WorkspaceInput::text($request, 'display_name', 160);
                $slug = trim(mb_substr(strtolower($this->slugger->slug($name)->toString()), 0, 150), '-').'-'.bin2hex(random_bytes(6));
                $creator = (new CreatorProfile($name, $slug))->setBio(WorkspaceInput::text($request, 'bio', 10000, false))->setVisibility(WorkspaceInput::visibility($request));
                $user = $this->getUser();
                if ($user instanceof User) { $creator->setOwner($user); }
                $this->em->persist($creator); $this->audit->record('video.workspace.creator.created', $creator, null, 'Creator-Profil angelegt.'); $this->em->flush();
            } catch (\InvalidArgumentException $e) { throw new UnprocessableEntityHttpException($e->getMessage(), $e); }
            return $this->redirectToRoute('app_admin_video_workspace');
        }
        return $this->privateResponse($this->render('video_workspace/creator_form.html.twig', ['row' => ['display_name' => '', 'bio' => '', 'visibility' => 'public'], 'token' => 'workspace-creator-new', 'version' => '', 'editing' => false]));
    }
    #[Route('/creators/{id}', name: 'app_admin_video_workspace_creator_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function creatorEdit(int $id, Request $request): Response
    {
        $this->enabled(); $row = $this->creators->find($id); $token = 'workspace-creator-'.$id;
        if ($request->isMethod('POST')) {
            $this->csrf($request, $token);
            try {
                $delete = $request->request->getString('action') === 'delete';
                $changes = $delete ? [] : ['display_name' => WorkspaceInput::text($request, 'display_name', 160), 'bio' => WorkspaceInput::text($request, 'bio', 10000, false), 'visibility' => WorkspaceInput::visibility($request)];
                $this->creators->mutate($id, $request->request->getString('_version'), $changes, $delete);
                $this->audit->record('video.workspace.creator.changed', CreatorProfile::class, $id, 'Creator-Profil verwaltet.'); $this->em->flush();
            } catch (\InvalidArgumentException $e) { throw new UnprocessableEntityHttpException($e->getMessage(), $e); }
            return $this->redirectToRoute('app_admin_video_workspace');
        }
        return $this->privateResponse($this->render('video_workspace/creator_form.html.twig', ['row' => $row, 'token' => $token, 'version' => $this->creators->version($row), 'editing' => true]));
    }

    #[Route('/videos/{id}/metadata', name: 'app_admin_video_workspace_metadata', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function metadata(Video $video, Request $request): Response
    {
        $this->enabled();
        $profile = $this->em->getRepository(VideoDiscoveryProfile::class)->findOneBy(['video' => $video]);
        if (!$profile instanceof VideoDiscoveryProfile) { $profile = new VideoDiscoveryProfile($video); }
        if ($request->isMethod('POST')) {
            $this->csrf($request, 'workspace-metadata-'.$video->getId());
            try {
                $visibility = WorkspaceInput::visibility($request); $discoverable = WorkspaceInput::flag($request, 'discoverable');
                $creatorId = WorkspaceInput::integer($request, 'creator_id', 2147483647);
                $creator = $creatorId === 0 ? null : $this->em->find(CreatorProfile::class, $creatorId);
                if ($creatorId !== 0 && !$creator instanceof CreatorProfile) { throw new \InvalidArgumentException('Creator nicht gefunden.'); }
                if ($visibility === 'private' && (!$creator instanceof CreatorProfile || $creator->getOwner() === null)) { throw new \InvalidArgumentException('Private Videos benötigen einen Creator mit Besitzer.'); }
                $tags = array_values(array_unique(array_filter(array_map('trim', explode(',', WorkspaceInput::text($request, 'tags', 3000, false))))));
                if (count($tags) > 30) { throw new \InvalidArgumentException('Maximal 30 Tags.'); }
                foreach ($tags as $tag) { if (mb_strlen($tag) > 100) { throw new \InvalidArgumentException('Ein Tag ist zu lang.'); } }
                $this->em->wrapInTransaction(function () use ($profile, $creator, $visibility, $discoverable, $tags): void {
                    $profile->setCreator($creator)->setVisibility($visibility)->setDiscoverable($discoverable); $profile->getTags()->clear();
                    foreach ($tags as $name) {
                        $slug = trim(mb_substr(strtolower($this->slugger->slug($name)->toString()), 0, 120), '-');
                        if ($slug === '') { throw new \InvalidArgumentException('Ungültiger Tag.'); }
                        $tag = $this->em->getRepository(VideoTag::class)->findOneBy(['slug' => $slug]);
                        if (!$tag instanceof VideoTag) { $tag = new VideoTag($name, $slug); $this->em->persist($tag); }
                        $profile->addTag($tag);
                    }
                    $this->em->persist($profile); $this->audit->record('video.workspace.metadata.saved', $profile, $profile->getId(), 'Video-Entdeckung verwaltet.');
                });
            } catch (\InvalidArgumentException $e) { throw new UnprocessableEntityHttpException($e->getMessage(), $e); }
            return $this->redirectToRoute('app_admin_video_workspace');
        }
        return $this->privateResponse($this->render('video_workspace/metadata.html.twig', ['video' => $video, 'profile' => $profile, 'creators' => $this->em->getRepository(CreatorProfile::class)->findBy([], ['displayName' => 'ASC'], 200)]));
    }

    #[Route('/sources/new', name: 'app_admin_video_workspace_source_new', methods: ['GET', 'POST'])]
    public function sourceNew(Request $request): Response { return $this->sourceForm(new VideoSource(), $request); }
    #[Route('/sources/{id}', name: 'app_admin_video_workspace_source_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function sourceEdit(VideoSource $source, Request $request): Response { return $this->sourceForm($source, $request); }

    #[Route('/legacy-live/{id}', name: 'app_admin_video_workspace_legacy_live', requirements: ['id' => '\\d+'], methods: ['GET', 'POST'])]
    public function legacyLive(int $id, Request $request, \App\VideoWorkspace\LegacyLiveManagement $gateway, \App\Video\Discovery\LiveEmbedPolicy $policy): Response
    {
        $this->enabled(); $row = $gateway->find($id); $token = 'workspace-legacy-live-'.$id;
        if ($request->isMethod('POST')) {
            $this->csrf($request, $token);
            try {
                $delete = $request->request->getString('action') === 'delete'; $changes = [];
                if (!$delete) {
                    $title = WorkspaceInput::text($request, 'title', 160); $provider = WorkspaceInput::text($request, 'provider', 16); $url = WorkspaceInput::text($request, 'source_url', 700);
                    $stream = new VideoLiveStream($title, $provider, $url);
                    if ($policy->resolve($stream, $request->getHost(), true) === null) { throw new \InvalidArgumentException('Ungültige Stream-URL.'); }
                    $creatorId = WorkspaceInput::integer($request, 'creator_id', 2147483647);
                    if ($creatorId !== 0 && !$this->em->find(CreatorProfile::class, $creatorId) instanceof CreatorProfile) { throw new \InvalidArgumentException('Creator nicht gefunden.'); }
                    $changes = ['title' => $title, 'provider' => $provider, 'source_url' => $url, 'creator_id' => $creatorId === 0 ? null : $creatorId, 'enabled' => WorkspaceInput::flag($request, 'enabled'), 'starts_at' => WorkspaceInput::startsAt($request)?->format('Y-m-d H:i:s')];
                }
                $gateway->mutate($id, $request->request->getString('_version'), $changes, $delete);
                $this->audit->record('video.workspace.live.changed', VideoLiveStream::class, $id, 'Bestehenden Livestream verwaltet.'); $this->em->flush();
            } catch (\InvalidArgumentException $e) { throw new UnprocessableEntityHttpException($e->getMessage(), $e); }
            return $this->redirectToRoute('app_admin_video_workspace');
        }
        return $this->privateResponse($this->render('video_workspace/legacy_live.html.twig', ['row' => $row, 'token' => $token, 'version' => $gateway->version($row), 'creators' => $this->em->getRepository(CreatorProfile::class)->findBy([], ['displayName' => 'ASC'], 200)]));
    }

    private function sourceForm(VideoSource $source, Request $request): Response
    {
        $this->enabled(); $token = 'workspace-source-'.($source->getId() ?? 'new');
        if ($request->isMethod('POST')) {
            $this->csrf($request, $token);
            try {
                $this->em->wrapInTransaction(function () use ($source, $request): void {
                    if ($source->getId() !== null) { $this->em->lock($source, LockMode::OPTIMISTIC, WorkspaceInput::integer($request, 'version', 2147483647)); }
                    if ($request->request->getString('action') === 'delete') {
                        if ($source->getId() === null) { throw $this->createNotFoundException(); }
                        $this->em->remove($source);
                    } else {
                        $label = WorkspaceInput::text($request, 'label', 160); $provider = WorkspaceInput::text($request, 'provider', 32); $url = WorkspaceInput::text($request, 'url', 700);
                        if ($this->providers->resolve($provider, $url, $request->getHost()) === null) { throw new \InvalidArgumentException('Die URL passt nicht zum Anbieter oder Quellformat.'); }
                        $videoId = WorkspaceInput::integer($request, 'video_id', 2147483647); $creatorId = WorkspaceInput::integer($request, 'creator_id', 2147483647);
                        $video = $videoId === 0 ? null : $this->em->find(Video::class, $videoId); $creator = $creatorId === 0 ? null : $this->em->find(CreatorProfile::class, $creatorId);
                        if (($videoId !== 0 && !$video instanceof Video) || ($creatorId !== 0 && !$creator instanceof CreatorProfile)) { throw new \InvalidArgumentException('Video oder Creator nicht gefunden.'); }
                        $source->configure($label, $provider, $url, WorkspaceInput::integer($request, 'position', 10000), WorkspaceInput::flag($request, 'enabled'), WorkspaceInput::flag($request, 'authorized'));
                        $source->setVideo($video)->setCreator($creator)->setStartsAt(WorkspaceInput::startsAt($request)); $this->em->persist($source);
                    }
                    $this->audit->record('video.workspace.source.changed', $source, $source->getId(), 'Freigegebene Videoquelle verwaltet.');
                });
            } catch (\Doctrine\ORM\OptimisticLockException $e) { throw new ConflictHttpException('Die Quelle wurde inzwischen geändert.', $e); }
            catch (\InvalidArgumentException $e) { throw new UnprocessableEntityHttpException($e->getMessage(), $e); }
            return $this->redirectToRoute('app_admin_video_workspace');
        }
        return $this->privateResponse($this->render('video_workspace/source_form.html.twig', ['source' => $source, 'token' => $token, 'catalogue' => $this->providers->catalogue(), 'videoId' => $request->query->getString('video', (string) ($source->getVideo()?->getId() ?? '0')), 'creators' => $this->em->getRepository(CreatorProfile::class)->findBy([], ['displayName' => 'ASC'], 200)]));
    }
    private function enabled(): void { if (!$this->module->enabled()) { throw $this->createNotFoundException(); } }
    private function csrf(Request $request, string $token): void { if (!$this->isCsrfTokenValid($token, $request->request->getString('_token'))) { throw $this->createAccessDeniedException(); } }
    private function privateResponse(Response $response): Response { $response->headers->set('Cache-Control', 'private, no-store'); $response->headers->set('X-Robots-Tag', 'noindex, nofollow'); return $response; }
}
