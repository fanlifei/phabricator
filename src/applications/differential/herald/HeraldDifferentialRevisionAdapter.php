<?php

final class HeraldDifferentialRevisionAdapter
  extends HeraldDifferentialAdapter {

  protected $revision;

  protected $buildPlans = array();

  protected $affectedPackages;
  protected $changesets;
  private $haveHunks;

  public function getAdapterApplicationClass() {
    return 'PhabricatorDifferentialApplication';
  }

  protected function newObject() {
    return new DifferentialRevision();
  }

  protected function initializeNewAdapter() {
    $this->revision = $this->newObject();
  }

  public function getObject() {
    return $this->revision;
  }

  public function getAdapterContentType() {
    return 'differential';
  }

  public function getAdapterContentName() {
    return pht('Differential Revisions');
  }

  public function getAdapterContentDescription() {
    return pht(
      "React to revisions being created or updated.\n".
      "Revision rules can send email, flag revisions, add reviewers, ".
      "and run build plans.");
  }

  public function supportsRuleType($rule_type) {
    switch ($rule_type) {
      case HeraldRuleTypeConfig::RULE_TYPE_GLOBAL:
      case HeraldRuleTypeConfig::RULE_TYPE_PERSONAL:
        return true;
      case HeraldRuleTypeConfig::RULE_TYPE_OBJECT:
      default:
        return false;
    }
  }

  public function getRepetitionOptions() {
    return array(
      HeraldRepetitionPolicyConfig::EVERY,
      HeraldRepetitionPolicyConfig::FIRST,
    );
  }

  public static function newLegacyAdapter(
    DifferentialRevision $revision,
    DifferentialDiff $diff) {
    $object = new HeraldDifferentialRevisionAdapter();

    // Reload the revision to pick up relationship information.
    $revision = id(new DifferentialRevisionQuery())
      ->withIDs(array($revision->getID()))
      ->setViewer(PhabricatorUser::getOmnipotentUser())
      ->needRelationships(true)
      ->needReviewerStatus(true)
      ->executeOne();

    $object->revision = $revision;
    $object->setDiff($diff);

    return $object;
  }

  public function getBuildPlans() {
    return $this->buildPlans;
  }

  public function getHeraldName() {
    return $this->revision->getTitle();
  }

  protected function loadChangesets() {
    if ($this->changesets === null) {
      $this->changesets = $this->getDiff()->loadChangesets();
    }
    return $this->changesets;
  }

  protected function loadChangesetsWithHunks() {
    $changesets = $this->loadChangesets();

    if ($changesets && !$this->haveHunks) {
      $this->haveHunks = true;

      id(new DifferentialHunkQuery())
        ->setViewer(PhabricatorUser::getOmnipotentUser())
        ->withChangesets($changesets)
        ->needAttachToChangesets(true)
        ->execute();
    }

    return $changesets;
  }

  public function loadAffectedPackages() {
    if ($this->affectedPackages === null) {
      $this->affectedPackages = array();

      $repository = $this->loadRepository();
      if ($repository) {
        $packages = PhabricatorOwnersPackage::loadAffectedPackages(
          $repository,
          $this->loadAffectedPaths());
        $this->affectedPackages = $packages;
      }
    }
    return $this->affectedPackages;
  }

  public function loadReviewers() {
    $reviewers = $this->getObject()->getReviewerStatus();
    return mpull($reviewers, 'getReviewerPHID');
  }

  public function getActions($rule_type) {
    switch ($rule_type) {
      case HeraldRuleTypeConfig::RULE_TYPE_GLOBAL:
        return array_merge(
          array(
            self::ACTION_APPLY_BUILD_PLANS,
          ),
          parent::getActions($rule_type));
      case HeraldRuleTypeConfig::RULE_TYPE_PERSONAL:
        return array_merge(
          array(),
          parent::getActions($rule_type));
    }
  }

  public function applyHeraldEffects(array $effects) {
    assert_instances_of($effects, 'HeraldEffect');

    $result = array();

    foreach ($effects as $effect) {
      $action = $effect->getAction();
      switch ($action) {
        case self::ACTION_APPLY_BUILD_PLANS:
          foreach ($effect->getTarget() as $phid) {
            $this->buildPlans[] = $phid;
          }
          $result[] = new HeraldApplyTranscript(
            $effect,
            true,
            pht('Applied build plans.'));
          break;
        default:
          $result[] = $this->applyStandardEffect($effect);
          break;
      }
    }
    return $result;
  }

}
