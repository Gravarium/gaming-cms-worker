<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\MediaAsset;
use App\Entity\ModuleStorageSetting;
use App\Entity\Video;
use App\Entity\VideoCategory;
use App\Entity\VideoPlaylist;
use App\Form\VideoCategoryType;
use App\Form\VideoPlaylistType;
use App\Form\VideoType;
use App\Repository\VideoCategoryRepository;
use App\Repository\VideoPlaylistRepository;
use App\Repository\VideoRepository;
use App\Service\MediaStorageManager;
use App\Service\MediaUrlPolicy;
use App\Service\VideoEmbedResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\String\Slugger\SluggerInterface;

#[Route('/admin/videos')]
#[IsGranted('CMS_VIDEO_MANAGE')]
final class AdminVideoController extends AbstractController
{
    private const MODULE_KEY = 'video';

    public function __construct(
        private readonly VideoRepository $videos,
        private readonly VideoCategoryRepository $categories,
        private readonly VideoPlaylistRepository $playlists,
        private readonly EntityManagerInterface $entityManager,
        private readonly SluggerInterface $slugger,
        private readonly MediaStorageManager $mediaStorage,
        private readonly MediaUrlPolicy $urlPolicy,
        private readonly VideoEmbedResolver $embedResolver,
    ) {
    }

    #[Route('', name: 'app_admin_video_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('admin/video/index.html.twig', [
            'videos' => $this->videos->findBy([], ['createdAt' => 'DESC']),
            'categories' => $this->categories->findBy([], ['name' => 'ASC']),
            'playlists' => $this->playlists->findBy([], ['title' => 'ASC']),
            'storageMode' => $this->mediaStorage->modeFor(self::MODULE_KEY),
        ]);
    }

    #[Route('/new', name: 'app_admin_video_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        return $this->videoForm(new Video(), $request, 'Video anlegen');
    }

    #[Route('/{id}/edit', name: 'app_admin_video_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Video $video, Request $request): Response
    {
        return $this->videoForm($video, $request, 'Video bearbeiten');
    }

    #[Route('/{id}/delete', name: 'app_admin_video_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Video $video, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('delete-video-'.$video->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
        $this->entityManager->remove($video);
        $this->entityManager->flush();
        $this->addFlash('success', 'Das Video wurde gelöscht. Die Mediendatei bleibt zur Sicherheit in der Medienbibliothek.');

        return $this->redirectToRoute('app_admin_video_index');
    }

    #[Route('/category/new', name: 'app_admin_video_category_new', methods: ['GET', 'POST'])]
    public function newCategory(Request $request): Response
    {
        return $this->categoryForm(new VideoCategory(), $request, 'Videokategorie anlegen');
    }

    #[Route('/category/{id}/edit', name: 'app_admin_video_category_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function editCategory(VideoCategory $category, Request $request): Response
    {
        return $this->categoryForm($category, $request, 'Videokategorie bearbeiten');
    }

    #[Route('/category/{id}/delete', name: 'app_admin_video_category_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function deleteCategory(VideoCategory $category, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('delete-video-category-'.$category->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
        $this->entityManager->remove($category);
        $this->entityManager->flush();
        $this->addFlash('success', 'Die Videokategorie wurde gelöscht.');

        return $this->redirectToRoute('app_admin_video_index');
    }

    #[Route('/playlist/new', name: 'app_admin_video_playlist_new', methods: ['GET', 'POST'])]
    public function newPlaylist(Request $request): Response
    {
        return $this->playlistForm(new VideoPlaylist(), $request, 'Playlist anlegen');
    }

    #[Route('/playlist/{id}/edit', name: 'app_admin_video_playlist_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function editPlaylist(VideoPlaylist $playlist, Request $request): Response
    {
        return $this->playlistForm($playlist, $request, 'Playlist bearbeiten');
    }

    #[Route('/playlist/{id}/delete', name: 'app_admin_video_playlist_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function deletePlaylist(VideoPlaylist $playlist, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('delete-video-playlist-'.$playlist->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
        $this->entityManager->remove($playlist);
        $this->entityManager->flush();
        $this->addFlash('success', 'Die Playlist wurde gelöscht.');

        return $this->redirectToRoute('app_admin_video_index');
    }

    private function videoForm(Video $video, Request $request, string $heading): Response
    {
        $form = $this->createForm(VideoType::class, $video)->handleRequest($request);
        $storageMode = $this->mediaStorage->modeFor(self::MODULE_KEY);
        $externalReady = $this->mediaStorage->externalUploadReady();

        if ($form->isSubmitted()) {
            $file = $form->get('videoFile')->getData();
            $isUpload = $video->getSourceType() === Video::SOURCE_UPLOAD;

            if ($isUpload && !$file instanceof UploadedFile && $video->getMediaAsset() === null) {
                $form->get('videoFile')->addError(new FormError('Bitte eine Videodatei auswählen.'));
            }
            if (!$isUpload && $video->getSourceUrl() === null) {
                $form->get('sourceUrl')->addError(new FormError('Bitte die Video- oder Plattform-URL eintragen.'));
            }
            if ($isUpload && $file instanceof UploadedFile
                && $storageMode === ModuleStorageSetting::MODE_EXTERNAL
                && !$externalReady
            ) {
                $form->get('videoFile')->addError(new FormError('Der externe Videospeicher ist noch nicht mit einem Medienziel verbunden.'));
            }
            if (!$isUpload && $video->getSourceUrl() !== null && $this->embedResolver->resolve($video, 'localhost') === null) {
                $form->get('sourceUrl')->addError(new FormError('Die URL passt nicht zur ausgewählten Videoquelle.'));
            }
            if ($video->getThumbnailUrl() !== null && !$this->urlPolicy->isSafeRemote($video->getThumbnailUrl())) {
                $form->get('thumbnailUrl')->addError(new FormError('Die Vorschaubild-URL ist nicht als sichere externe Medienadresse erlaubt.'));
            }

            if ($form->isValid()) {
                /** @var list<MediaAsset> $newAssets */
                $newAssets = [];
                try {
                    $video->setSlug($this->uniqueVideoSlug($video->getTitle(), $video->getId()));
                    if ($isUpload) {
                        $video->setSourceUrl(null);
                        if ($file instanceof UploadedFile) {
                            $asset = $this->mediaStorage->storeUpload($file, self::MODULE_KEY, $video->getTitle());
                            $newAssets[] = $asset;
                            $video->setMediaAsset($asset);
                        }
                    } else {
                        $video->setMediaAsset(null);
                    }
                    if ($video->getId() === null) { $this->entityManager->persist($video); }
                    $this->mediaStorage->flushWithRollback(...$newAssets);
                    $this->addFlash('success', 'Das Video wurde gespeichert.');

                    return $this->redirectToRoute('app_admin_video_index');
                } catch (\DomainException|\RuntimeException $exception) {
                    $this->mediaStorage->discardUncommitted(...$newAssets);
                    $form->addError(new FormError($exception->getMessage()));
                }
            }
        }

        return $this->render('admin/video/form.html.twig', [
            'form' => $form,
            'heading' => $heading,
            'video' => $video,
            'storageMode' => $storageMode,
            'externalStorageReady' => $externalReady,
        ]);
    }

    private function categoryForm(VideoCategory $category, Request $request, string $heading): Response
    {
        $form = $this->createForm(VideoCategoryType::class, $category)->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $category->setSlug($this->uniqueSlug($category->getName(), $category->getId(), fn (string $slug, ?int $id): bool => $this->categories->slugExists($slug, $id), 140));
            if ($category->getId() === null) { $this->entityManager->persist($category); }
            $this->entityManager->flush();
            $this->addFlash('success', 'Die Videokategorie wurde gespeichert.');

            return $this->redirectToRoute('app_admin_video_index');
        }

        return $this->render('admin/video/simple_form.html.twig', ['form' => $form, 'heading' => $heading]);
    }

    private function playlistForm(VideoPlaylist $playlist, Request $request, string $heading): Response
    {
        $form = $this->createForm(VideoPlaylistType::class, $playlist)->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $playlist->setSlug($this->uniqueSlug($playlist->getTitle(), $playlist->getId(), fn (string $slug, ?int $id): bool => $this->playlists->slugExists($slug, $id), 180));
            if ($playlist->getId() === null) { $this->entityManager->persist($playlist); }
            $this->entityManager->flush();
            $this->addFlash('success', 'Die Playlist wurde gespeichert.');

            return $this->redirectToRoute('app_admin_video_index');
        }

        return $this->render('admin/video/simple_form.html.twig', ['form' => $form, 'heading' => $heading]);
    }

    private function uniqueVideoSlug(string $title, ?int $exceptId): string
    {
        return $this->uniqueSlug($title, $exceptId, fn (string $slug, ?int $id): bool => $this->videos->slugExists($slug, $id), 200);
    }

    /** @param callable(string, ?int): bool $exists */
    private function uniqueSlug(string $value, ?int $exceptId, callable $exists, int $maxLength): string
    {
        if ($maxLength < 1) {
            throw new \InvalidArgumentException('The video slug storage limit must be positive.');
        }

        $base = mb_strtolower($this->slugger->slug($value)->toString()) ?: 'video';
        $base = rtrim(mb_substr($base, 0, $maxLength), '-');
        if ($base === '') {
            $base = mb_substr('video', 0, $maxLength);
        }

        $slug = $base;
        $number = 2;
        while ($exists($slug, $exceptId)) {
            $tail = '-'.$number++;
            $remaining = $maxLength - mb_strlen($tail);
            if ($remaining < 1) {
                throw new \RuntimeException('A unique video slug cannot fit within its storage limit.');
            }

            $prefix = rtrim(mb_substr($base, 0, $remaining), '-');
            if ($prefix === '') {
                $prefix = mb_substr('video', 0, $remaining);
            }
            $slug = $prefix.$tail;
        }

        return $slug;
    }
}
