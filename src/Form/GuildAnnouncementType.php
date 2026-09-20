<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\GuildAnnouncement;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** @extends AbstractType<GuildAnnouncement> */
final class GuildAnnouncementType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('title', null, ['label' => 'Titel'])->add('body', TextareaType::class, ['label' => 'Mitteilung', 'attr' => ['rows' => 9]])->add('pinned', CheckboxType::class, ['label' => 'Oben anheften', 'required' => false]);
    }
    public function configureOptions(OptionsResolver $resolver): void { $resolver->setDefaults(['data_class' => GuildAnnouncement::class]); }
}
