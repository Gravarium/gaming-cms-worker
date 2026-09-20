<?php

declare(strict_types=1);
namespace App\Form;
use App\Entity\ContentTag;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
/** @extends AbstractType<ContentTag> */
final class ContentTagType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void { $builder->add('name', null, ['label' => 'Name'])->add('description', TextareaType::class, ['label' => 'Beschreibung', 'required' => false, 'attr' => ['rows' => 4, 'maxlength' => 500]]); }
    public function configureOptions(OptionsResolver $resolver): void { $resolver->setDefaults(['data_class' => ContentTag::class]); }
}
