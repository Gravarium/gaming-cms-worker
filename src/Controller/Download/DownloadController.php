<?php
declare(strict_types=1);
namespace App\Controller\Download;
use App\Downloads\DownloadAuthorization;
use App\Downloads\DownloadModuleAvailability;
use App\Downloads\DownloadPrivateStorage;
use App\Entity\Download\DownloadPackage;
use App\Entity\Download\DownloadVersion;
use App\Entity\User;
use App\Repository\Download\DownloadPackageRepository;
use App\Repository\Download\DownloadVersionRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
#[Route('/downloads')]
final class DownloadController extends AbstractController
{
    public function __construct(private readonly DownloadPackageRepository $packages,private readonly DownloadVersionRepository $versions,private readonly DownloadAuthorization $authorization,private readonly DownloadPrivateStorage $storage,private readonly DownloadModuleAvailability $availability){}
    #[Route('',name:'app_download_index',methods:['GET'])]
    public function index():Response
    {
        $this->assertAvailable();$user=$this->user();
        $packages=array_values(array_filter($this->packages->enabledPackages(),fn(DownloadPackage $p):bool=>$this->authorization->canDownload($p,$user)));
        return $this->render('download/index.html.twig',['packages'=>$packages]);
    }
    #[Route('/{slug}',name:'app_download_show',requirements:['slug'=>'[a-z0-9-]+'],methods:['GET'])]
    public function show(string $slug):Response
    {
        $this->assertAvailable();$package=$this->packages->enabledBySlug($slug);
        if(!$package instanceof DownloadPackage||!$this->authorization->canDownload($package,$this->user()))throw $this->createNotFoundException();
        return $this->render('download/show.html.twig',['package'=>$package,'versions'=>$this->versions->forPackage($package)]);
    }
    #[Route('/{slug}/file/{id}',name:'app_download_file',requirements:['slug'=>'[a-z0-9-]+','id'=>'\d+'],methods:['GET'])]
    public function deliver(string $slug,DownloadVersion $version):Response
    {
        $this->assertAvailable();$package=$version->getPackage();
        if($package->getSlug()!==$slug||!$this->authorization->canDownload($package,$this->user())||!$version->isDeliverable())throw $this->createNotFoundException();
        $response=new BinaryFileResponse($this->storage->absolutePath($version->getStorageReference()));
        $response->setContentDisposition('attachment',$version->getSafeFilename());
        $response->headers->set('X-Content-Type-Options','nosniff');
        $response->headers->set('Cache-Control','private, no-store');
        return $response;
    }
    private function assertAvailable():void{if(!$this->availability->enabled())throw $this->createNotFoundException();}
    private function user():?User{$user=$this->getUser();return $user instanceof User?$user:null;}
}
