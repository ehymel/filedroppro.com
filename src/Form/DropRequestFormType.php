<?php

namespace App\Form;

use App\Entity\DropRequest;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\NotBlank;

class DropRequestFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('clientName', TextType::class, [
                'label' => 'Client / Patient Name',
                'required' => true,
                'attr' => ['placeholder' => 'Jane Doe'],
                'constraints' => [
                    new NotBlank(message: 'Please provide a name for the invitee.'),
                ],
                'row_attr' => ['class' => 'form-floating mb-2'],
            ])
            ->add('clientEmail', EmailType::class, [
                'label' => 'Client Email Address',
                'required' => true,
                'attr' => ['placeholder' => 'jane.doe@example.com'],
                'constraints' => [
                    new NotBlank(message:  'Please provide an email address for the invitee.'),
                    new Email(message:  'Please enter a valid business email address.'),
                ],
                'row_attr' => ['class' => 'form-floating mb-2'],
            ])
            ->add('instructions', TextareaType::class, [
                'label' => 'Custom Instructions (Optional)',
                'required' => false,
                'attr' => ['placeholder' => 'Please upload your documents here'],
                'row_attr' => ['class' => 'form-floating mb-2'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => DropRequest::class,
        ]);
    }
}
