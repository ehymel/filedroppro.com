<?php

namespace App\Form\User;

use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

class RegistrationFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('email', EmailType::class, [
                'label' => 'Email Address',
                'disabled' => $options['has_invitation'], // Lock email if registering via secure invite
            ])
            ->add('plainPassword', RepeatedType::class, [
                'type' => PasswordType::class,
                'mapped' => false,
                'invalid_message' => 'The password fields must match.',
                'options' => ['attr' => ['class' => 'password-field']],
                'required' => true,
                'first_options'  => ['label' => 'Password'],
                'second_options' => ['label' => 'Repeat Password'],
                'constraints' => [
                    new NotBlank(message: 'Please enter a password'),
                    new Length(min: 12, max: 4096, minMessage: 'For security, your password must be at least {{ limit }} characters')
                ],
            ]);

        // If the registering user does NOT have an invitation, render tenant creation/joining controls
        if (!$options['has_invitation']) {
            $builder
                ->add('registrationMode', ChoiceType::class, [
                    'label' => 'Registration Type',
                    'mapped' => false,
                    'choices' => [
                        'Register a new firm / practice' => 'new',
                        'Request to join an existing firm' => 'join',
                    ],
                    'expanded' => true, // Render as radio buttons
                    'multiple' => false,
                    'data' => 'new',
                ])
                ->add('firmName', TextType::class, [
                    'label' => 'Firm / Practice Name',
                    'mapped' => false,
                    'required' => false,
                    'attr' => ['placeholder' => 'e.g. Apex Medical Clinic']
                ])
                ->add('joinCode', TextType::class, [
                    'label' => 'Organization Join Code',
                    'mapped' => false,
                    'required' => false,
                    'attr' => ['placeholder' => 'e.g. TX-XYZ-1234']
                ]);

            // Add POST_SUBMIT event listener to enforce strict conditional validation
            $builder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event) {
                $form = $event->getForm();
                $mode = $form->get('registrationMode')->getData();

                if ($mode === 'new') {
                    $firmName = trim((string) $form->get('firmName')->getData());
                    if (empty($firmName)) {
                        $form->get('firmName')->addError(new FormError('Please enter your Firm or Practice name.'));
                    }
                }

                if ($mode === 'join') {
                    $joinCode = trim((string) $form->get('joinCode')->getData());
                    if (empty($joinCode)) {
                        $form->get('joinCode')->addError(new FormError('A secure Join Code is required to request access.'));
                    }
                }
            });
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
            'has_invitation' => false,
        ]);

        $resolver->setAllowedTypes('has_invitation', 'bool');
    }
}
