<?php

declare(strict_types=1);

namespace App\Form\Newsletter;

use App\Entity\Newsletter\NewsletterCampaign;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** @extends AbstractType<NewsletterCampaign> */
final class NewsletterCampaignType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', null, ['label' => 'Interner Titel'])
            ->add('subject', null, ['label' => 'Betreff'])
            ->add('bodyText', TextareaType::class, ['label' => 'Text', 'attr' => ['rows' => 16]])
            ->add('segment', ChoiceType::class, [
                'label' => 'Segment',
                'choices' => [
                    'Alle bestätigten Abonnenten' => NewsletterCampaign::SEGMENT_ALL,
                    'Verknüpfte Mitgliederkonten' => NewsletterCampaign::SEGMENT_MEMBERS,
                    'Abonnenten ohne Konto' => NewsletterCampaign::SEGMENT_GUESTS,
                ],
            ])
            ->add('maxRecipients', IntegerType::class, [
                'label' => 'Maximale Empfängerzahl',
                'attr' => ['min' => 1, 'max' => 10000],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => NewsletterCampaign::class]);
    }
}
