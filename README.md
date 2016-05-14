# ActiveFinder
yii 1 custom version of CActiveFinder to get SQL generated by AR from CDbCriteria.

## Usage
```
$model = new User();
$criteria = new CDbCriteria();
$criteria->select = 't.id';
$command = ActiveFinder::getCommand($model, $criteria);
// $command variable have the SQL text
return $command->queryColumn();
```
