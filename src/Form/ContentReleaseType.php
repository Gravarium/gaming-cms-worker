<?php

declare(strict_types=1);
namespace App\Form;
use App\Entity\ContentEntry;
use App\Entity\ContentRelease;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
/** @extends AbstractType<ContentRelease> */
final class ContentReleaseType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('name', null, ['label' => 'Release-Name'])->add('description', TextareaType::class, ['label' => 'Beschreibung', 'required' => false, 'attr' => ['rows' => 4, 'maxlength' => 1000]])->add('entries', EntityType::class, ['label' => 'Inhalte', 'class' => ContentEntry::class, 'choice_label' => 'title', 'multiple' => true, 'expanded' => true, 'required' => false])->add('status', ChoiceType::class, ['label' => 'Status', 'choices' => ['Entwurf' => ContentRelease::STATUS_DRAFT, 'Geplant' => ContentRelease::STATUS_SCHEDULED, 'Abgebrochen' => ContentRelease::STATUS_CANCELLED]])->add('scheduledAt', DateTimeType::class, ['label' => 'Zeitpunkt', 'required' => false, 'widget' => 'single_text', 'help' => 'Für geplante Releases erforderlich.']);
    }
    public function configureOptions(OptionsResolver $resolver): void { $resolver->setDefaults(['data_class' => ContentRelease::class]); }
}
