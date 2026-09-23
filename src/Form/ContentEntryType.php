<?php

declare(strict_types=1);
namespace App\Form;
use App\Entity\Category;
use App\Entity\ContentEntry;
use App\Entity\ContentTag;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
/** @extends AbstractType<ContentEntry> */
final class ContentEntryType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('type', ChoiceType::class, ['label' => 'Inhaltstyp', 'choices' => ['News-Beitrag' => ContentEntry::TYPE_NEWS, 'Seite' => ContentEntry::TYPE_PAGE]])
            ->add('title', null, ['label' => 'Titel'])
            ->add('subtitle', null, ['label' => 'Untertitel', 'required' => false])
            ->add('slug', null, ['label' => 'Slug', 'required' => false, 'help' => 'Leer lassen für automatische Erzeugung. Bei Änderung bleibt der alte öffentliche Slug als Weiterleitung erhalten.'])
            ->add('category', EntityType::class, ['label' => 'Kategorie', 'class' => Category::class, 'choice_label' => 'displayName', 'placeholder' => 'Keine Kategorie', 'required' => false])
            ->add('tags', EntityType::class, ['label' => 'Tags', 'class' => ContentTag::class, 'choice_label' => 'name', 'multiple' => true, 'expanded' => true, 'required' => false])
            ->add('excerpt', TextareaType::class, ['label' => 'Kurztext', 'required' => false, 'attr' => ['rows' => 3, 'maxlength' => 500]])
            ->add('body', TextareaType::class, ['label' => 'Inhalt', 'attr' => ['rows' => 12, 'data-content-editor-target' => 'source', 'class' => 'content-editor-source']])
            ->add('status', ChoiceType::class, ['label' => 'Workflow-Status', 'choices' => ['Entwurf' => ContentEntry::STATUS_DRAFT, 'Zur Freigabe' => ContentEntry::STATUS_REVIEW, 'Geplant' => ContentEntry::STATUS_SCHEDULED, 'Veröffentlicht' => ContentEntry::STATUS_PUBLISHED, 'Archiviert' => ContentEntry::STATUS_ARCHIVED]])
            ->add('scheduledAt', DateTimeType::class, ['label' => 'Veröffentlichung planen', 'required' => false, 'widget' => 'single_text', 'help' => 'Nur für den Status „Geplant“.'])
            ->add('scheduledUnpublishAt', DateTimeType::class, ['label' => 'Veröffentlichung automatisch beenden', 'required' => false, 'widget' => 'single_text', 'help' => 'Optional. Muss nach Veröffentlichung bzw. geplantem Start liegen.'])
            ->add('featured', CheckboxType::class, ['label' => 'Hervorgehoben', 'required' => false])
            ->add('pinned', CheckboxType::class, ['label' => 'Oben anheften', 'required' => false])
            ->add('unlisted', CheckboxType::class, ['label' => 'Nicht listen', 'required' => false, 'help' => 'Direkter Link bleibt erreichbar, der Inhalt erscheint aber nicht in Listen und Suche.'])
            ->add('seoTitle', null, ['label' => 'SEO-Titel', 'required' => false])
            ->add('seoDescription', TextareaType::class, ['label' => 'SEO-Beschreibung', 'required' => false, 'attr' => ['rows' => 3, 'maxlength' => 320]])
            ->add('canonicalUrl', null, ['label' => 'Canonical URL', 'required' => false])
            ->add('noIndex', CheckboxType::class, ['label' => 'Suchmaschinen: noindex', 'required' => false]);
    }
    public function configureOptions(OptionsResolver $resolver): void { $resolver->setDefaults(['data_class' => ContentEntry::class]); }
}
