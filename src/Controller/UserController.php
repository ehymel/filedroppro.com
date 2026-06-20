<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\User\UserEditForm;
use App\Form\User\UserPasswordResetForm;
use App\Form\User\UserPasswordResetRequestForm;
use App\Form\User\UserPasswordSetForm;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('user', name: 'user_')]
class UserController extends AbstractController
{
    public function __construct(private readonly string $kernelSecret)
    {
    }

    #[Route('/edit', name: 'edit')]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function edit(Request $request, UserPasswordHasherInterface $passwordHasher, EntityManagerInterface $em): Response
    {
        $user = $this->getUser();
        $form = $this->createForm(UserEditForm::class, $user);

        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            /** @var User $user */
            $user = $form->getData();

            if ($user->plainPassword) {
                $user->password = $passwordHasher->hashPassword($user, $user->plainPassword);
            }

            $em->persist($user);
            $em->flush();

            $this->addFlash('success', 'User profile updated.');

            return $this->redirectToRoute('user_edit');
        }

        return $this->render('user/edit.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route('activate/{id}/{hash}', name: 'activate')]
    public function activate(User $user, Request $request, EntityManagerInterface $em): Response
    {
        $submittedHash = $request->attributes->get('hash');
        $savedHash = $user->confirmationHash;

        if ($submittedHash === $savedHash) {
            // Display "is activated" in admin user edit page
            // has no effect on login!!
            $user->isActivated = true;

            // create new confirmation hash to pass to set_password script (for security)
            $hash = md5(time().$this->kernelSecret);
            $user->confirmationHash = $hash;

            $em->persist($user);
            $em->flush();

            $this->addFlash('success', 'Your account has been activated.');

            return $this->redirectToRoute('user_set_password', [
                'id' => $user->id,
                'hash' => $hash,
            ]);
        }

        return $this->render('user/activation_failed.html.twig');
    }

    #[Route('/password_set/{id}/{hash}', name: 'set_password')]
    public function setPassword(User $user, Request $request, UserPasswordHasherInterface $passwordHasher, EntityManagerInterface $em): ?Response
    {
        $submittedHash = $request->attributes->get('hash');
        $savedHash = $user->confirmationHash;

        if ($submittedHash !== $savedHash) {
            return $this->render('user/password_reset_failed.html.twig');
        }

        $form = $this->createForm(UserPasswordSetForm::class, $user);

        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            /** @var User $user */
            $user = $form->getData();

            $user->password = $passwordHasher->hashPassword($user, $user->plainPassword);
            $user->confirmationHash = null;

            $em->persist($user);
            $em->flush();

            $this->addFlash('success', 'Welcome '.$user->name);

            return $this->redirectToRoute('security_login');
        }

        return $this->render('user/password_set.html.twig', [
            'form' => $form,
            'username' => $user->getUserIdentifier(),
        ]);
    }

    #[Route('password_reset/{id}/{hash}', name: 'reset_password')]
    public function resetPassword(User $user, Request $request, UserPasswordHasherInterface $passwordHasher,
                                  TokenStorageInterface $tokenStorage, EntityManagerInterface $em): Response
    {
        $submittedHash = $request->attributes->get('hash');
        $savedHash = $user->confirmationHash;

        if ($submittedHash !== $savedHash) {
            return $this->render('user/password_reset_failed.html.twig');
        }

        // force logout of previous user
        $tokenStorage->setToken(null);

        $form = $this->createForm(UserPasswordResetForm::class, $user);

        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            /** @var User $user */
            $user = $form->getData();

            $user->password = $passwordHasher->hashPassword($user, $user->plainPassword);
            $user->confirmationHash = null;

            $em->persist($user);
            $em->flush();

            $this->addFlash(
                'success',
                'Successfully changed password for '.$user->name
            );

            return $this->redirectToRoute('login_redirect');
        }

        return $this->render('user/password_reset.html.twig', [
            'form' => $form,
            'username' => $user->getUserIdentifier(),
        ]);
    }

    #[Route('/password_reset_request', name: 'request_reset_pw')]
    public function requestResetPwLink(Request $request, MailerInterface $mailer, UserRepository $userRepository, EntityManagerInterface $em): Response
    {
        $form = $this->createForm(UserPasswordResetRequestForm::class);

        $form->handleRequest($request);
        if ($form->isSubmitted()) {
            $data = $form->getData();

            $user = $userRepository->findOneByEmailOrUsername($data['_username'], $data['_email']);

            if (!$user instanceof User) {
                $this->addFlash(
                    'danger',
                    'No user found with that username or email. Try again?'
                );

                return $this->redirectToRoute('user_request_reset_pw');
            }

            // generate random hash and persist to db for later comparison from link in reset password email
            $hash = md5(time().$this->kernelSecret);
            $user->confirmationHash = $hash;

            $em->persist($user);
            $em->flush();

            // now send link to password reset page email with above hash
            $email = new TemplatedEmail()
                ->to($user->email)
                ->subject('FileDrop Pro Password Reset')
                ->htmlTemplate('emails/user_reset_password.html.twig')
                ->context([
                    'user' => $user,
                    'hash' => $hash,
                ])
            ;

            $mailer->send($email);

            $this->addFlash(
                'success',
                'Activation email sent.'
            );

            return $this->redirect('/login');
        }

        return $this->render('user/password_reset_request.html.twig', [
            'form' => $form,
        ]);
    }
}
