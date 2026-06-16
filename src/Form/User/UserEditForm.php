<?php

namespace App\Form\User;

use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class UserEditForm extends AbstractType
{
    /**
     * Used for user to edit their own profile;
     * Does not allow change of username or roles.
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('lastName', null, [
                'row_attr' => ['class' => 'form-floating'],
            ])
            ->add('firstName', null, [
                'row_attr' => ['class' => 'form-floating'],
            ])
            ->add('credentials', null, [
                'row_attr' => ['class' => 'form-floating'],
            ])
//            ->add('username')
            ->add('email', EmailType::class, [
                'row_attr' => ['class' => 'form-floating'],
            ])
            ->add('plainPassword', RepeatedType::class, [
                'type' => PasswordType::class,
                'label' => [
                    'data-help' => 'Leave blank for no change',
                ],
                'row_attr' => ['class' => 'form-floating'],
            ])
            ->add('cellNumber', TelType::class, [
                'label' => 'Cell #',
                'row_attr' => ['class' => 'form-floating'],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
            'validation_groups' => ['Default', 'EditUser'],
        ]);
    }
}
