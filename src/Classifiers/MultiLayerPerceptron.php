<?php

namespace Rubix\ML\Classifiers;

use Rubix\ML\Online;
use Rubix\ML\Verbose;
use Rubix\Tensor\Matrix;
use Rubix\ML\Persistable;
use Rubix\ML\Probabilistic;
use Rubix\ML\Datasets\Dataset;
use Rubix\ML\Datasets\Labeled;
use Rubix\ML\NeuralNet\Snapshot;
use Rubix\ML\Other\Helpers\Params;
use Rubix\ML\NeuralNet\FeedForward;
use Rubix\ML\Other\Helpers\DataType;
use Rubix\ML\Other\Functions\Argmax;
use Rubix\ML\NeuralNet\Layers\Hidden;
use Rubix\ML\Other\Traits\LoggerAware;
use Rubix\ML\NeuralNet\Optimizers\Adam;
use Rubix\ML\NeuralNet\Layers\Multiclass;
use Rubix\ML\NeuralNet\Layers\Placeholder1D;
use Rubix\ML\NeuralNet\Optimizers\Optimizer;
use Rubix\ML\CrossValidation\Metrics\Metric;
use Rubix\ML\CrossValidation\Metrics\F1Score;
use Rubix\ML\NeuralNet\CostFunctions\CostFunction;
use Rubix\ML\NeuralNet\CostFunctions\CrossEntropy;
use Rubix\ML\Other\Specifications\EstimatorIsCompatibleWithMetric;
use Rubix\ML\Other\Specifications\DatasetIsCompatibleWithEstimator;
use InvalidArgumentException;
use RuntimeException;

/**
 * Multi Layer Perceptron
 *
 * A multiclass feedforward Neural Network classifier that uses a series of
 * user-defined hidden layers as intermediate computational units. Multiple
 * layers and non-linear activation functions allow the Multi Layer Perceptron
 * to handle complex non-linear problems.
 *
 * > **Note**: The MLP features progress monitoring which stops training when it can
 * no longer make progress. It also utilizes [snapshotting](#snapshots) to make sure
 * that it always uses the best parameters even if progress may have declined during
 * training.
 *
 * References:
 * [1] G. E. Hinton. (1989). Connectionist learning procedures.
 *
 * @category    Machine Learning
 * @package     Rubix/ML
 * @author      Andrew DalPino
 */
class MultiLayerPerceptron implements Online, Probabilistic, Verbose, Persistable
{
    use LoggerAware;

    /**
     * The user-specified hidden layers of the network.
     *
     * @var array
     */
    protected $hidden;

    /**
     * The number of training samples to consider per iteholdoutn of gradient descent.
     *
     * @var int
     */
    protected $batchSize;

    /**
     * The gradient descent optimizer used to train the network.
     *
     * @var \Rubix\ML\NeuralNet\Optimizers\Optimizer
     */
    protected $optimizer;

    /**
     * The L2 regularization parameter.
     *
     * @var float
     */
    protected $alpha;

    /**
     * The maximum number of training epochs. i.e. the number of times to iterate
     * over the entire training set before the algorithm terminates.
     *
     * @var int
     */
    protected $epochs;

    /**
     * The minimum change in the cost function necessary to continue training.
     *
     * @var float
     */
    protected $minChange;

    /**
     * The function that computes the cost of an erroneous activation during
     * training.
     *
     * @var \Rubix\ML\NeuralNet\CostFunctions\CostFunction
     */
    protected $costFn;

    /**
     * The holdout of training samples to use for validation. i.e. the holdout
     * holdout.
     *
     * @var float
     */
    protected $holdout;

    /**
     * The Validation metric used to validate the performance of the model.
     *
     * @var \Rubix\ML\CrossValidation\Metrics\Metric
     */
    protected $metric;

    /**
     * The training window to consider during early stop checking i.e. the last
     * n epochs.
     *
     * @var int
     */
    protected $window;

    /**
     * The unique class labels.
     *
     * @var array
     */
    protected $classes = [
        //
    ];

    /**
     * The underlying computational graph.
     *
     * @var \Rubix\ML\NeuralNet\FeedForward|null
     */
    protected $network;

    /**
     * The validation scores at each epoch.
     *
     * @var array
     */
    protected $scores = [
        //
    ];

    /**
     * The average cost of a training sample at each epoch.
     *
     * @var array
     */
    protected $steps = [
        //
    ];

    /**
     * @param array $hidden
     * @param int $batchSize
     * @param \Rubix\ML\NeuralNet\Optimizers\Optimizer|null $optimizer
     * @param float $alpha
     * @param int $epochs
     * @param float $minChange
     * @param \Rubix\ML\NeuralNet\CostFunctions\CostFunction $costFn
     * @param float $holdout
     * @param \Rubix\ML\CrossValidation\Metrics\Metric|null $metric
     * @param int $window
     * @throws \InvalidArgumentException
     */
    public function __construct(
        array $hidden = [],
        int $batchSize = 100,
        ?Optimizer $optimizer = null,
        float $alpha = 1e-4,
        int $epochs = 1000,
        float $minChange = 1e-4,
        ?CostFunction $costFn = null,
        float $holdout = 0.1,
        ?Metric $metric = null,
        int $window = 3
    ) {
        if ($batchSize < 1) {
            throw new InvalidArgumentException('Cannot have less than 1 sample'
                . " per batch, $batchSize given.");
        }

        if ($alpha < 0.) {
            throw new InvalidArgumentException('L2 regularization amount must'
                . " be 0 or greater, $alpha given.");
        }

        if ($epochs < 1) {
            throw new InvalidArgumentException('Estimator must train for at'
                . " least 1 epoch, $epochs given.");
        }

        if ($minChange < 0.) {
            throw new InvalidArgumentException('Minimum change cannot be less'
                . " than 0, $minChange given.");
        }

        if ($holdout < 0.01 or $holdout > 0.5) {
            throw new InvalidArgumentException('Holdout ratio must be between'
                . " 0.01 and 0.5, $holdout given.");
        }

        if (isset($metric)) {
            EstimatorIsCompatibleWithMetric::check($this, $metric);
        } else {
            $metric = new F1Score();
        }

        if ($window < 2) {
            throw new InvalidArgumentException('Window must be at least 2'
                . " epochs, $window given.");
        }

        if (is_null($optimizer)) {
            $optimizer = new Adam();
        }

        if (is_null($costFn)) {
            $costFn = new CrossEntropy();
        }

        $this->hidden = $hidden;
        $this->batchSize = $batchSize;
        $this->optimizer = $optimizer;
        $this->alpha = $alpha;
        $this->epochs = $epochs;
        $this->minChange = $minChange;
        $this->costFn = $costFn;
        $this->holdout = $holdout;
        $this->metric = $metric;
        $this->window = $window;
    }

    /**
     * Return the integer encoded estimator type.
     *
     * @return int
     */
    public function type() : int
    {
        return self::CLASSIFIER;
    }

    /**
     * Return the data types that this estimator is compatible with.
     *
     * @return int[]
     */
    public function compatibility() : array
    {
        return [
            DataType::CONTINUOUS,
        ];
    }

    /**
     * Has the learner been trained?
     *
     * @return bool
     */
    public function trained() : bool
    {
        return isset($this->network);
    }

    /**
     * Return the validation scores at each epoch.
     *
     * @return array
     */
    public function scores() : array
    {
        return $this->scores;
    }

    /**
     * Return the average cost at every epoch.
     *
     * @return array
     */
    public function steps() : array
    {
        return $this->steps;
    }

    /**
     * Return the underlying neural network instance or null if not trained.
     *
     * @return \Rubix\ML\NeuralNet\FeedForward|null
     */
    public function network() : ?FeedForward
    {
        return $this->network;
    }

    /**
    * @param  \Rubix\ML\Datasets\Dataset  $dataset
    * @throws \InvalidArgumentException
    */
    public function train(Dataset $dataset) : void
    {
        if (!$dataset instanceof Labeled) {
            throw new InvalidArgumentException('This estimator requires a'
                . ' labeled training set.');
        }

        $this->classes = $dataset->possibleOutcomes();

        $this->network = new FeedForward(
            new Placeholder1D($dataset->numColumns()),
            $this->hidden,
            new Multiclass($this->classes, $this->alpha, $this->costFn),
            $this->optimizer
        );

        $this->scores = $this->steps = [];

        $this->partial($dataset);
    }

    /**
     * Train the network using mini-batch gradient descent with backpropagation.
     *
     * @param \Rubix\ML\Datasets\Dataset $dataset
     * @throws \InvalidArgumentException
     */
    public function partial(Dataset $dataset) : void
    {
        if (is_null($this->network)) {
            $this->train($dataset);
            
            return;
        }

        if (!$dataset instanceof Labeled) {
            throw new InvalidArgumentException('This estimator requires a'
                . ' labeled training set.');
        }

        DatasetIsCompatibleWithEstimator::check($dataset, $this);

        if ($this->logger) {
            $this->logger->info('Learner initialized w/ '
                . Params::stringify([
                    'hidden' => $this->hidden,
                    'batch_size' => $this->batchSize,
                    'optimizer' => $this->optimizer,
                    'alpha' => $this->alpha,
                    'epochs' => $this->epochs,
                    'min_change' => $this->minChange,
                    'cost_fn' => $this->costFn,
                    'hold_out' => $this->holdout,
                    'metric' => $this->metric,
                    'window' => $this->window,
                ]));
        }

        $n = $dataset->numRows();

        [$testing, $training] = $dataset->stratifiedSplit($this->holdout);

        [$min, $max] = $this->metric->range();

        $bestScore = $min;
        $bestSnapshot = null;
        $previous = INF;

        for ($epoch = 1; $epoch <= $this->epochs; $epoch++) {
            $batches = $training->randomize()->batch($this->batchSize);

            $loss = 0.;

            foreach ($batches as $batch) {
                $loss += $this->network->roundtrip($batch);
            }

            $loss /= $n;

            $predictions = $this->predict($testing);

            $score = $this->metric->score($predictions, $testing->labels());

            $this->steps[] = $loss;
            $this->scores[] = $score;

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestSnapshot = Snapshot::take($this->network);
            }

            if ($this->logger) {
                $this->logger->info("Epoch $epoch complete,"
                    . " score=$score loss=$loss");
            }

            if (is_nan($loss) or is_nan($score)) {
                break 1;
            }

            if (abs($previous - $loss) < $this->minChange) {
                break 1;
            }

            if ($loss < self::EPSILON or $score >= $max) {
                break 1;
            }

            if ($epoch > $this->window) {
                $window = array_slice($this->scores, -$this->window);

                $worst = $window;
                rsort($worst);

                if ($window === $worst) {
                    break 1;
                }
            }

            $previous = $loss;
        }

        if (end($this->scores) < $bestScore) {
            if ($bestSnapshot) {
                $this->network->restore($bestSnapshot);

                if ($this->logger) {
                    $this->logger->info('Network restored'
                        . ' from previous snapshot');
                }
            }
        }

        if ($this->logger) {
            $this->logger->info('Training complete');
            $this->logger->info("Best validation score=$bestScore");
        }
    }

    /**
     * Make predictions from a dataset.
     *
     * @param \Rubix\ML\Datasets\Dataset $dataset
     * @return array
     */
    public function predict(Dataset $dataset) : array
    {
        return array_map([Argmax::class, 'compute'], $this->proba($dataset));
    }

    /**
     * Estimate probabilities for each possible outcome.
     *
     * @param \Rubix\ML\Datasets\Dataset $dataset
     * @throws \InvalidArgumentException
     * @throws \RuntimeException
     * @return array
     */
    public function proba(Dataset $dataset) : array
    {
        if (is_null($this->network)) {
            throw new RuntimeException('The learner has not'
                . ' not been trained.');
        };

        DatasetIsCompatibleWithEstimator::check($dataset, $this);

        $samples = Matrix::quick($dataset->samples())->transpose();

        $probabilities = [];

        foreach ($this->network->infer($samples)->transpose() as $activations) {
            $probabilities[] = array_combine($this->classes, $activations);
        }

        return $probabilities;
    }
}
