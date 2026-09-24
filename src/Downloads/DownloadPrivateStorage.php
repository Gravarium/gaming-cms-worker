<?php
declare(strict_types=1);
namespace App\Downloads;
use App\Service\MediaMalwareScanner;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
final readonly class DownloadPrivateStorage
{
    public function __construct(private MediaMalwareScanner $scanner,#[Autowire('%kernel.project_dir%')] private string $projectDir){}
    /** @return array{reference:string,filename:string,sha256:string,scan:string} */
    public function store(UploadedFile $file):array
    {
        if(!$file->isValid())throw new \DomainException('Upload is invalid.');
        $original=$file->getClientOriginalName();
        $ext=mb_strtolower(pathinfo($original,PATHINFO_EXTENSION));
        if($ext===''||in_array($ext,['php','phtml','phar','cgi','pl','sh','exe','dll','bat','cmd','js','html','htm','svg'],true))throw new \DomainException('Executable or unsafe download type rejected.');
        $base=preg_replace('/[^A-Za-z0-9._-]+/','-',pathinfo($original,PATHINFO_FILENAME))?:'download';
        $filename=trim($base,'-_.').'-'.bin2hex(random_bytes(8)).'.'.$ext;
        if(!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,254}$/D',$filename))throw new \DomainException('Safe filename could not be produced.');
        $scannerStatus=$this->scanner->status();
        $this->scanner->scan($file->getPathname());
        $scan=$scannerStatus==='ready'?'clean':'unavailable';
        $sha=hash_file('sha256',$file->getPathname());
        if(!is_string($sha)||!preg_match('/^[a-f0-9]{64}$/D',$sha))throw new \RuntimeException('SHA-256 could not be calculated.');
        $dir=$this->projectDir.'/var/private-downloads';
        if(!is_dir($dir)&&!mkdir($dir,0700,true)&&!is_dir($dir))throw new \RuntimeException('Private download directory unavailable.');
        $reference=date('Y/m').'/'.$filename;
        $targetDir=$dir.'/'.date('Y/m');
        if(!is_dir($targetDir)&&!mkdir($targetDir,0700,true)&&!is_dir($targetDir))throw new \RuntimeException('Private download directory unavailable.');
        $file->move($targetDir,$filename);
        return ['reference'=>$reference,'filename'=>$filename,'sha256'=>$sha,'scan'=>$scan];
    }
    public function absolutePath(string $reference):string
    {
        if($reference===''||str_starts_with($reference,'/')||str_contains($reference,'..'))throw new \DomainException('Invalid private storage reference.');
        $root=realpath($this->projectDir.'/var/private-downloads');
        if($root===false)throw new \RuntimeException('Private download storage unavailable.');
        $path=realpath($root.'/'.$reference);
        if($path===false||!str_starts_with($path,$root.DIRECTORY_SEPARATOR)||!is_file($path)||is_link($path))throw new \DomainException('Download file is unavailable.');
        return $path;
    }
}
