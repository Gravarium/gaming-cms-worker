<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Video;
use App\Entity\VideoCategory;
use App\Entity\VideoPlaylist;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\File;

/** @extends AbstractType<Video> */
final class VideoType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', null, [
                'label' => 'Titel',
                'attr' => ['maxlength' => 180],
            ])
            ->add('category', EntityType::class, [
                'class' => VideoCategory::class,
                'choice_label' => 'name',
                'label' => 'Kategorie',
                'required' => false,
                'placeholder' => 'Keine Kategorie',
            ])
            ->add('playlists', EntityType::class, [
                'class' => VideoPlaylist::class,
                'choice_label' => 'title',
                'label' => 'Playlists',
                'required' => false,
                'multiple' => true,
                'expanded' => true,
                'attr' => ['class' => 'option-grid'],
            ])
            ->add('description', TextareaType::class, ['label' => 'Beschreibung', 'attr' => ['rows' => 8]])
            ->add('sourceType', ChoiceType::class, [
                'label' => 'Videoquelle',
                'choices' => [
                    'Datei hochladen' => Video::SOURCE_UPLOAD,
                    'YouTube' => Video::SOURCE_YOUTUBE,
                    'Vimeo' => Video::SOURCE_VIMEO,
                    'Twitch' => Video::SOURCE_TWITCH,
                    'Direkte externe Videoadresse' => Video::SOURCE_EXTERNAL,
                ],
            ])
            ->add('videoFile', FileType::class, [
                'label' => 'Videodatei hochladen',
                'mapped' => false,
                'required' => false,
                'help' => 'MP4, WebM oder MOV. Das Speicherziel des Video-Moduls wird automatisch verwendet.',
                'constraints' => [new File(
                    maxSize: '500M',
                    mimeTypes: ['video/mp4', 'video/webm', 'video/quicktime'],
                    mimeTypesMessage: 'Bitte eine MP4-, WebM- oder MOV-Datei auswählen.',
                )],
            ])
            ->add('sourceUrl', UrlType::class, [
                'label' => 'Video- oder Plattform-URL',
                'required' => false,
                'help' => 'Für YouTube, Vimeo, Twitch oder eine direkte Videodatei.',
            ])
            ->add('thumbnailUrl', UrlType::class, [
                'label' => 'Vorschaubild-URL',
                'required' => false,
            ])
            ->add('durationLabel', null, [
                'label' => 'Laufzeit',
                'required' => false,
                'attr' => ['placeholder' => 'z. B. 12:34'],
            ])
            ->add('publishedAt', DateTimeType::class, [
                'label' => 'Veröffentlichen ab',
                'required' => false,
                'widget' => 'single_text',
                'help' => 'Leer lassen, um das Video als Entwurf zu speichern.',
            ])
            ->add('featured', CheckboxType::class, ['label' => 'Hervorgehoben anzeigen', 'required' => false])
            ->add('enabled', CheckboxType::class, ['label' => 'Video aktivieren', 'required' => false]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Video::class]);
    }
}
